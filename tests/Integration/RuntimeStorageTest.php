<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Database\SqlSearch;
use App\Platform\Storage\FileScope;
use App\Platform\Storage\FileStorageService;
use App\Platform\Storage\ObjectStorage;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use GuzzleHttp\Client;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use Yiisoft\Db\Query\Query;

/**
 * FileStorageService against the real S3 endpoint (SeaweedFS in dev) and the test database.
 */
final class RuntimeStorageTest extends IntegrationTestCase
{
    private FileStorageService $files;
    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $storage = $this->get(ObjectStorage::class);
        if (!$storage->isConfigured()) {
            self::markTestSkipped('S3 is not configured (S3_ENDPOINT/S3_KEY/S3_SECRET).');
        }
        $storage->ensureBucket();
        $this->files = $this->get(FileStorageService::class);
    }

    protected function tearDown(): void
    {
        $fs = $this->get(ObjectStorage::class)->filesystem();
        foreach ($this->created as $id) {
            $path = (new Query($this->db))->select('path')->from('stored_files')->where(['id' => $id])->scalar();
            if ($path) {
                $fs->delete((string) $path);
            }
            $this->db->createCommand()->delete('stored_files', ['id' => $id])->execute();
        }
        parent::tearDown();
    }

    private function upload(string $content, string $name): \Psr\Http\Message\UploadedFileInterface
    {
        return (new UploadedFileFactory())->createUploadedFile((new StreamFactory())->createStream($content), strlen($content), UPLOAD_ERR_OK, $name, 'application/octet-stream');
    }

    public function testStoreDownloadPresignAndSoftDelete(): void
    {
        $tenant = Uuid::v7();
        $content = "id,name\n1,alpha\n2,beta\n";
        $row = $this->files->store($this->upload($content, 'export.csv'), null, $tenant, 'project', 'p-1');
        $this->created[] = $row['id'];
        self::assertSame(hash('sha256', $content), $row['checksum_sha256']);
        self::assertStringStartsWith('t-', $row['path']);
        self::assertStringNotContainsString('export', $row['path'], 'storage path is randomised');
        self::assertTrue((new Query($this->db))->from('audit_log')->where(['action' => 'file.uploaded', 'entity_id' => $row['id']])->exists());

        $own = new FileScope($tenant);
        $opened = $this->files->open($row['id'], $own, null);
        self::assertSame($content, stream_get_contents($opened['stream']));

        $url = $this->files->presignedUrl($row['id'], $own, null, 60);
        self::assertStringContainsString('X-Amz-Signature', $url);
        $resp = (new Client(['http_errors' => false, 'timeout' => 10]))->get($url);
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame($content, (string) $resp->getBody());

        $page = $this->files->list($own, ['q' => 'EXPO', 'mime' => 'text/'], 1);
        self::assertSame(1, $page->total, 'case-insensitive search + MIME prefix filter');
        self::assertSame(1, $this->files->totals($own)['files']);

        // tenant isolation: another tenant cannot see it (IDOR → not found)
        try {
            $this->files->find($row['id'], new FileScope(Uuid::v7()));
            self::fail('foreign tenant must not see the file');
        } catch (NotFoundException) {
        }
        self::assertSame($row['id'], $this->files->find($row['id'], FileScope::all())['id'], 'cross-tenant scope sees everything');

        $this->files->softDelete($row['id'], $own, null);
        $this->expectException(NotFoundException::class);
        $this->files->open($row['id'], $own, null);
    }

    public function testInvalidUploadIsNotStored(): void
    {
        $before = (int) (new Query($this->db))->from('stored_files')->count();
        try {
            $this->files->store($this->upload("\x7FELF\x02\x01\x01" . str_repeat("\x00", 40), 'tool.txt'), null, null);
            self::fail('executable must be rejected');
        } catch (ValidationException) {
        }
        self::assertSame($before, (int) (new Query($this->db))->from('stored_files')->count());
    }

    public function testSqlSearchEscapesWildcards(): void
    {
        $cond = SqlSearch::contains('50%_off!', ['original_name']);
        $sql = (new Query($this->db))->from('stored_files')->where($cond)->createCommand()->getRawSql();
        self::assertStringContainsString("'%50!%!_off!!%'", $sql);
        $this->expectException(\InvalidArgumentException::class);
        SqlSearch::contains('x', ['name; DROP TABLE users']);
    }
}
