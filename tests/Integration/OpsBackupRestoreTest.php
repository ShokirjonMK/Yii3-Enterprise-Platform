<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Backup\BackupService;
use App\Platform\Storage\ObjectStorage;
use Yiisoft\Db\Query\Query;

/**
 * Real backup + restore verification against the test database (platform_test) and S3 (SeaweedFS):
 * pg_dump -Fc with a consistent snapshot → S3 upload → download → pg_restore into verify_<ts> → row counts → DROP.
 */
final class OpsBackupRestoreTest extends IntegrationTestCase
{
    public function testBackupUploadVerifyAndCleanup(): void
    {
        /** @var ObjectStorage $storage */
        $storage = $this->get(ObjectStorage::class);
        if (!$storage->isConfigured()) {
            self::markTestSkipped('S3 is not configured');
        }
        $storage->ensureBucket();
        /** @var BackupService $backups */
        $backups = $this->get(BackupService::class);
        $this->db->createCommand("UPDATE backups SET status = 'failed', error = 'stale (test cleanup)' WHERE status = 'running'")->execute();

        $b = $backups->run(null, 'test');
        self::assertSame('succeeded', $b['status']);
        self::assertSame('platform_test', $b['database_name']);
        self::assertMatchesRegularExpression('#^s3://[^/]+/backups/\d{4}-\d{2}-\d{2}/platform_test-\d{8}T\d{6}Z-[0-9a-f]{8}\.dump$#', (string) $b['location']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $b['checksum_sha256']);
        self::assertGreaterThan(1000, (int) $b['size_bytes']);
        $key = BackupService::keyFromLocation((string) $b['location']);
        self::assertTrue($storage->filesystem()->fileExists($key));
        self::assertSame('PGDMP', substr((string) $storage->filesystem()->read($key), 0, 5), 'custom-format dump');
        $counts = $b['verify_details']['source_counts'];
        self::assertArrayHasKey('users', $counts);
        self::assertArrayHasKey('audit_log', $counts);
        self::assertNotNull($b['expires_at']);

        $result = $backups->verify((string) $b['id']);
        self::assertTrue($result['passed'], 'restore verification passed: ' . json_encode($result['error'] ?? $result['mismatches'] ?? null));
        self::assertTrue($result['checksum_ok']);
        self::assertTrue($result['dropped']);
        ksort($counts);
        $restored = $result['restored_counts'];
        ksort($restored);
        self::assertSame($counts, $restored);
        self::assertMatchesRegularExpression('/^verify_\d{14}_[0-9a-f]{4}$/', $result['database']);
        $exists = $this->db->createCommand('SELECT count(*) FROM pg_database WHERE datname = :d', [':d' => $result['database']])->queryScalar();
        self::assertSame(0, (int) $exists, 'temporary database dropped');

        $row = $backups->get((string) $b['id']);
        self::assertSame('passed', $row['verify_status']);
        self::assertTrue((new Query($this->db))->from('audit_log')->where(['action' => 'backup.verified', 'entity_id' => $b['id']])->exists());
        self::assertTrue((new Query($this->db))->from('audit_log')->where(['action' => 'backup.completed', 'entity_id' => $b['id']])->exists());

        // Tampered object → checksum failure is detected
        $storage->filesystem()->write($key, 'corrupted');
        $bad = $backups->verify((string) $b['id']);
        self::assertFalse($bad['passed']);
        self::assertFalse($bad['checksum_ok']);

        // Retention removes the object and marks the row expired
        $this->db->createCommand("UPDATE backups SET expires_at = now() - interval '1 minute' WHERE id = :id", [':id' => $b['id']])->execute();
        self::assertGreaterThanOrEqual(1, $backups->purgeExpired());
        self::assertSame('expired', $backups->get((string) $b['id'])['status']);
        self::assertFalse($storage->filesystem()->fileExists($key));
    }

    public function testPasswordNeverOnCommandLineAndPassfileEscaping(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Platform/Backup/BackupService.php');
        self::assertStringNotContainsString("'--password", $src);
        self::assertStringNotContainsString('PGPASSWORD', $src);
        $m = new \ReflectionMethod(BackupService::class, 'pgpassEscape');
        self::assertSame('a\\:b\\\\c', $m->invoke(null, 'a:b\\c'));
    }

    public function testWalStatus(): void
    {
        $wal = $this->get(BackupService::class)->walStatus();
        self::assertArrayHasKey('archive_mode', $wal);
        self::assertArrayHasKey('healthy', $wal);
    }
}
