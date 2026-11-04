<?php

declare(strict_types=1);

namespace App\Platform\Storage;

use App\Platform\Audit\AuditLogger;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use App\Platform\Database\SqlSearch;
use App\Shared\Infrastructure\Database\Sql;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Application service for stored files (`stored_files` + S3 object storage). Used by Admin → Storage and
 * reusable by business modules (attachments):
 *
 *   $file = $files->store($upload, $actorId, $tenantId, 'project', $projectId);
 *   $files->stream($file['id'], $scope)  /  $files->presignedUrl($file['id'], $scope)
 *
 * Tenant isolation: every read takes a {@see FileScope}; objects of other tenants are reported as not found (IDOR).
 * Objects are private; downloads go through the application or short-lived pre-signed URLs.
 */
final class FileStorageService
{
    public const SORTABLE = [
        'created_at' => 'created_at',
        'name' => 'original_name',
        'size' => 'size_bytes',
        'mime' => 'mime_type',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ObjectStorage $storage,
        private readonly UploadValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    public function validator(): UploadValidator
    {
        return $this->validator;
    }

    /**
     * Validates and stores an upload. The DB row is written only after the object was stored; if the
     * insert fails the object is removed again (no orphans).
     *
     * @return array<string,mixed> stored_files row
     */
    public function store(UploadedFileInterface $upload, ?string $actorId, ?string $tenantId, ?string $entityType = null, ?string $entityId = null): array
    {
        $valid = $this->validator->validateUploaded($upload);
        return $this->storeValidated($valid, $actorId, $tenantId, $entityType, $entityId);
    }

    /** @return array<string,mixed> */
    public function storeValidated(ValidatedUpload $valid, ?string $actorId, ?string $tenantId, ?string $entityType = null, ?string $entityId = null): array
    {
        if (!$this->storage->isConfigured()) {
            throw new ConflictException('Object storage is not configured.', 'storage_not_configured');
        }
        $path = UploadValidator::storagePath($valid->extension, $tenantId === null ? 'global' : 't-' . substr(str_replace('-', '', $tenantId), 0, 16));
        $stream = fopen($valid->path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Uploaded file is not readable.');
        }
        try {
            $this->storage->filesystem()->writeStream($path, $stream, ['ContentType' => $valid->mimeType, 'mimetype' => $valid->mimeType]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $id = Uuid::v7();
        $row = [
            'id' => $id,
            'tenant_id' => $tenantId,
            'owner_id' => $actorId,
            'disk' => 's3',
            'path' => $path,
            'original_name' => $valid->originalName,
            'mime_type' => $valid->mimeType,
            'size_bytes' => $valid->size,
            'checksum_sha256' => $valid->sha256,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ];
        try {
            $this->db->transaction(function () use ($row, $actorId): void {
                $this->db->createCommand()->insert('stored_files', $row)->execute();
                $this->audit->record('file.uploaded', 'stored_file', $row['id'], null, array_diff_key($row, ['path' => 1]), category: 'data', actorId: $actorId);
            });
        } catch (Throwable $e) {
            try {
                $this->storage->filesystem()->delete($path);
            } catch (Throwable) {
            }
            throw $e;
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function find(string $id, FileScope $scope, bool $withDeleted = false): array
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundException('File not found.');
        }
        $q = (new Query($this->db))->from('stored_files')->where(['id' => $id]);
        if (!$withDeleted) {
            $q->andWhere(['deleted_at' => null]);
        }
        $this->applyScope($q, $scope);
        return $q->one() ?? throw new NotFoundException('File not found.');
    }

    /**
     * @param array{q?:string, mime?:string, entity_type?:string, deleted?:string} $filters
     */
    public function list(FileScope $scope, array $filters, int $page = 1, int $perPage = 25, ?string $sort = null): Page
    {
        $q = (new Query($this->db))->from('stored_files');
        $this->applyScope($q, $scope);
        if (($filters['deleted'] ?? '') === 'only') {
            $q->andWhere(['not', ['deleted_at' => null]]);
        } elseif (($filters['deleted'] ?? '') !== 'all') {
            $q->andWhere(['deleted_at' => null]);
        }
        if (($filters['q'] ?? '') !== '') {
            $q->andWhere(SqlSearch::contains((string) $filters['q'], ['original_name', 'checksum_sha256', 'entity_id']));
        }
        if (($filters['mime'] ?? '') !== '') {
            $q->andWhere(SqlSearch::startsWith('mime_type', (string) $filters['mime']));
        }
        if (($filters['entity_type'] ?? '') !== '') {
            $q->andWhere(['entity_type' => (string) $filters['entity_type']]);
        }
        return Paginator::paginate($q, $page, $perPage, $sort, self::SORTABLE, '-created_at');
    }

    /** @return array{files:int, bytes:int, deleted:int} */
    public function totals(FileScope $scope): array
    {
        $q = (new Query($this->db))->select([
            'files' => new Expression('count(*) FILTER (WHERE deleted_at IS NULL)'),
            'bytes' => new Expression('coalesce(sum(size_bytes) FILTER (WHERE deleted_at IS NULL), 0)'),
            'deleted' => new Expression('count(*) FILTER (WHERE deleted_at IS NOT NULL)'),
        ])->from('stored_files');
        $this->applyScope($q, $scope);
        $r = $q->one() ?? [];
        return ['files' => (int) ($r['files'] ?? 0), 'bytes' => (int) ($r['bytes'] ?? 0), 'deleted' => (int) ($r['deleted'] ?? 0)];
    }

    /**
     * Opens the object for streaming through the application. Integrity is verified by the caller if needed
     * (checksum_sha256 is part of the row).
     *
     * @return array{row: array<string,mixed>, stream: resource}
     */
    public function open(string $id, FileScope $scope, ?string $actorId): array
    {
        $row = $this->find($id, $scope);
        $stream = $this->storage->filesystem()->readStream((string) $row['path']);
        $this->audit->record('file.downloaded', 'stored_file', $id, metadata: ['via' => 'app'], category: 'data', actorId: $actorId);
        return ['row' => $row, 'stream' => $stream];
    }

    public function presignedUrl(string $id, FileScope $scope, ?string $actorId, int $ttlSeconds = 300): string
    {
        $row = $this->find($id, $scope);
        $ttlSeconds = max(30, min(3600, $ttlSeconds));
        $url = $this->storage->presignedUrl((string) $row['path'], $ttlSeconds);
        $this->audit->record('file.presigned', 'stored_file', $id, metadata: ['ttl' => $ttlSeconds], category: 'data', actorId: $actorId);
        return $url;
    }

    /** Soft delete: the row is marked deleted; the object is kept until the retention job purges it. */
    public function softDelete(string $id, FileScope $scope, ?string $actorId): void
    {
        $row = $this->find($id, $scope);
        $this->db->transaction(function () use ($id, $row, $actorId): void {
            $this->db->createCommand()->update('stored_files', ['deleted_at' => new Expression('now()')], ['id' => $id, 'deleted_at' => null])->execute();
            $this->audit->record('file.deleted', 'stored_file', $id, ['original_name' => $row['original_name'], 'size_bytes' => $row['size_bytes']], ['deleted' => true], category: 'data', severity: 'notice', actorId: $actorId);
        });
    }

    private function applyScope(Query $q, FileScope $scope): void
    {
        if ($scope->crossTenant) {
            return;
        }
        $q->andWhere(['tenant_id' => $scope->tenantId]);
    }
}
