<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Platform\Storage\ObjectStorage;
use App\Shared\Domain\Uuid;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use App\Tests\Support\WebClient;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Query\Query;

/**
 * 14 · Storage & Search over HTTP (real S3 bucket).
 */
final class StorageAdminTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        $db = Fixtures::db();
        $fs = TestContainer::service(ObjectStorage::class)->filesystem();
        foreach ((new Query($db))->select(['id', 'path'])->from('stored_files')->where(['like', 'original_name', 'e2e-'])->all() as $r) {
            try {
                $fs->delete((string) $r['path']);
            } catch (\Throwable) {
            }
            $db->createCommand()->delete('stored_files', ['id' => $r['id']])->execute();
        }
        Fixtures::cleanup();
    }

    private function upload(WebClient $web, string $name, string $content): ResponseInterface
    {
        $web->get('/admin/storage');
        $http = new Client(['base_uri' => getenv('E2E_BASE_URL') ?: 'http://nginx:8080', 'cookies' => $web->cookies(), 'http_errors' => false, 'allow_redirects' => true, 'headers' => ['Accept-Language' => 'en']]);
        return $http->post('/admin/storage/upload', ['multipart' => [
            ['name' => '_csrf', 'contents' => $web->csrf()],
            ['name' => 'entity_type', 'contents' => 'project'],
            ['name' => 'file', 'contents' => $content, 'filename' => $name],
        ]]);
    }

    public function testUploadDownloadPresignDelete(): void
    {
        [$web, $user] = AdminSession::login(['devops_sre']);
        $web->get('/admin/storage');
        self::assertStringContainsString('Up', $web->body());
        $name = 'e2e-' . bin2hex(random_bytes(3)) . '.csv';
        $content = "a,b\n1,2\n";
        $r = $this->upload($web, $name, $content);
        self::assertSame(200, $r->getStatusCode());
        $db = Fixtures::db();
        $row = (new Query($db))->from('stored_files')->where(['original_name' => $name])->one();
        self::assertNotNull($row, 'row stored');
        self::assertSame(hash('sha256', $content), $row['checksum_sha256']);
        self::assertTrue(TestContainer::service(ObjectStorage::class)->filesystem()->fileExists((string) $row['path']), 'object stored in S3');

        $web->get('/admin/storage?q=' . substr($name, 0, 10));
        self::assertStringContainsString($name, $web->body());

        $r = $web->get('/admin/storage/files/' . $row['id'] . '/download');
        self::assertSame($content, $web->body());
        self::assertStringStartsWith('attachment;', $r->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', $r->getHeaderLine('Content-Security-Policy'));

        $web->get('/admin/storage');
        $web->htmx('POST', '/admin/storage/files/' . $row['id'] . '/presign', ['ttl' => '120']);
        self::assertStringContainsString('X-Amz-Signature', $web->body());
        self::assertTrue((new Query($db))->from('audit_log')->where(['action' => 'file.presigned', 'entity_id' => $row['id']])->exists());

        // delete requires step-up
        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $user['id']])->execute();
        $web->get('/admin/storage');
        $web->post('/admin/storage/files/' . $row['id'] . '/delete');
        self::assertStringStartsWith('/auth/confirm', $web->url());
        AdminSession::reauth($web, $user);
        $web->get('/admin/storage');
        $web->post('/admin/storage/files/' . $row['id'] . '/delete');
        self::assertNotNull((new Query($db))->select('deleted_at')->from('stored_files')->where(['id' => $row['id']])->scalar() ?: null);
        self::assertSame(404, $web->get('/admin/storage/files/' . $row['id'] . '/download')->getStatusCode());
    }

    public function testRejectedUploadsShowErrors(): void
    {
        [$web] = AdminSession::login(['devops_sre']);
        $r = $this->upload($web, 'e2e-x.png.exe', "MZ\x90\x00");
        self::assertSame(422, $r->getStatusCode());
        $r = $this->upload($web, 'e2e-fake.png', 'plain text, not an image');
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('does not match', (string) $r->getBody());
        self::assertFalse((new Query(Fixtures::db()))->from('stored_files')->where(['like', 'original_name', 'e2e-'])->exists());
    }

    public function testTenantIsolationAndAuditorReadOnly(): void
    {
        $db = Fixtures::db();
        $foreign = Uuid::v7();
        $db->createCommand()->insert('stored_files', [
            'id' => $foreign, 'tenant_id' => Uuid::v7(), 'path' => 'global/x/' . bin2hex(random_bytes(8)) . '.txt', 'original_name' => 'e2e-foreign.txt',
            'mime_type' => 'text/plain', 'size_bytes' => 1, 'checksum_sha256' => str_repeat('0', 64),
        ])->execute();

        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/storage')->getStatusCode());
        self::assertStringNotContainsString('e2e-foreign.txt', $web->body(), 'other tenant\'s files are not listed');
        self::assertStringNotContainsString('enctype="multipart/form-data"', $web->body(), 'no upload form for auditors');
        self::assertSame(404, $web->get('/admin/storage/files/' . $foreign . '/download')->getStatusCode(), 'IDOR → 404');
        self::assertSame(403, $web->post('/admin/storage/files/' . $foreign . '/delete')->getStatusCode());
        self::assertSame(403, $web->post('/admin/storage/upload')->getStatusCode());

        $web->get('/admin/storage/search?q=adm');
        self::assertStringContainsString('pg_trgm', $web->body());
        self::assertStringContainsString('Users search plan', $web->body());
    }
}
