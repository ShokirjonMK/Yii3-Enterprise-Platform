<?php

declare(strict_types=1);

namespace App\Admin\Storage;

use App\Platform\Access\AccessService;
use App\Platform\Database\PostgresInspector;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Storage\FileScope;
use App\Platform\Storage\FileStorageService;
use App\Platform\Storage\ObjectStorage;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use App\Shared\Infrastructure\Logging\Redactor;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 14 · Storage & Search — S3 health, stored files (upload with server-side validation, authorised download,
 * pre-signed URL, soft delete) and PostgreSQL search diagnostics (pg_trgm / full-text).
 */
final class StorageController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ObjectStorage $storage,
        private readonly FileStorageService $files,
        private readonly PostgresInspector $pg,
        private readonly CurrentUser $currentUser,
        private readonly AccessService $access,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderIndex($request);
    }

    private function renderIndex(ServerRequestInterface $request, array $errors = [], int $status = 200): ResponseInterface
    {
        $qp = $request->getQueryParams();
        $filters = [
            'q' => mb_substr(trim((string) ($qp['q'] ?? '')), 0, 100),
            'mime' => preg_replace('#[^a-z0-9./+-]#', '', strtolower((string) ($qp['mime'] ?? ''))),
            'entity_type' => mb_substr(trim((string) ($qp['entity_type'] ?? '')), 0, 96),
            'deleted' => in_array($qp['deleted'] ?? '', ['only', 'all'], true) ? (string) $qp['deleted'] : '',
        ];
        $scope = $this->scope();
        [$health, $details] = $this->storage->health();
        if (isset($details['error'])) {
            $details['error'] = Redactor::redactString((string) $details['error']);
        }
        return $this->responder->render('@admin/Storage/views/index', [
            'health' => $health,
            'details' => $details,
            'totals' => $this->files->totals($scope),
            'page' => $this->files->list($scope, $filters, (int) ($qp['page'] ?? 1), 25, (string) ($qp['sort'] ?? '')),
            'filters' => $filters,
            'sort' => (string) ($qp['sort'] ?? ''),
            'maxBytes' => $this->files->validator()->maxBytes(),
            'extensions' => $this->files->validator()->allowedExtensions(),
            'errors' => $errors,
            'crossTenant' => $scope->crossTenant,
        ], status: $status);
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return $this->renderIndex($request, ['file' => ['No file was uploaded.']], 422);
        }
        $body = (array) $request->getParsedBody();
        $entityType = trim((string) ($body['entity_type'] ?? '')) ?: null;
        $entityId = trim((string) ($body['entity_id'] ?? '')) ?: null;
        if (($entityType !== null && !preg_match('/^[a-z0-9_.-]{1,96}$/', $entityType)) || ($entityId !== null && mb_strlen($entityId) > 96)) {
            return $this->renderIndex($request, ['entity_type' => ['Entity type: lowercase letters, digits, . _ - (max 96).']], 422);
        }
        $identity = $this->identity();
        try {
            $row = $this->files->store($file, $identity->user->id, $identity->user->tenantId, $entityType, $entityId);
        } catch (ValidationException $e) {
            return $this->renderIndex($request, $e->errors(), 422);
        } catch (ConflictException $e) {
            return $this->renderIndex($request, ['file' => [$e->getMessage()]], 409);
        }
        $this->responder->success(sprintf('"%s" uploaded (%s).', $row['original_name'], substr((string) $row['checksum_sha256'], 0, 12)));
        return $this->responder->redirect('admin.storage');
    }

    /** Authorised download through the application: attachment + nosniff + sandbox CSP. */
    public function download(CurrentRoute $route): ResponseInterface
    {
        $opened = $this->files->open((string) $route->getArgument('id'), $this->scope(), $this->currentUser->getId());
        $row = $opened['row'];
        $name = (string) $row['original_name'];
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'download';
        $mime = (string) $row['mime_type'];
        // never let the browser render active content inline
        $safeMime = preg_match('#^(application/pdf|image/(png|jpeg|gif|webp)|text/plain|text/csv)$#', $mime) ? $mime : 'application/octet-stream';
        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $safeMime)
            ->withHeader('Content-Length', (string) (int) $row['size_bytes'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Checksum-SHA256', (string) $row['checksum_sha256'])
            ->withBody($this->streams->createStreamFromResource($opened['stream']));
    }

    /** HTMX fragment with a short-lived pre-signed GET URL (audited). */
    public function presign(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $ttl = (int) (((array) $request->getParsedBody())['ttl'] ?? 300);
        $id = (string) $route->getArgument('id');
        $url = $this->files->presignedUrl($id, $this->scope(), $this->currentUser->getId(), $ttl);
        return $this->responder->fragment('@admin/Storage/views/_presigned', ['url' => $url, 'ttl' => max(30, min(3600, $ttl)), 'id' => $id]);
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $this->files->softDelete((string) $route->getArgument('id'), $this->scope(), $this->currentUser->getId());
        $this->responder->success('File deleted (soft delete; the object is retained until purge).');
        return $this->responder->redirect('admin.storage');
    }

    public function search(ServerRequestInterface $request): ResponseInterface
    {
        $term = mb_substr(trim((string) ($request->getQueryParams()['q'] ?? '')), 0, 100);
        $diag = null;
        $error = null;
        if ($term !== '') {
            try {
                $diag = $this->pg->searchDiagnostics($term);
            } catch (Throwable $e) {
                $error = Redactor::redactString($e->getMessage());
            }
        }
        return $this->responder->render('@admin/Storage/views/search', [
            'extensions' => $this->pg->extensions(),
            'indexes' => $this->pg->searchIndexes(),
            'term' => $term,
            'diag' => $diag,
            'error' => $error,
        ]);
    }

    private function identity(): Identity
    {
        $i = $this->currentUser->getIdentity();
        if (!$i instanceof Identity) {
            throw new \App\Shared\Domain\Exception\AccessDeniedException();
        }
        return $i;
    }

    private function scope(): FileScope
    {
        $user = $this->identity()->user;
        return new FileScope($user->tenantId, $this->access->can($user->id, 'tenants.cross'));
    }
}
