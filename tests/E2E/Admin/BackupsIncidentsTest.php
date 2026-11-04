<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Platform\Backup\BackupService;
use App\Platform\Storage\ObjectStorage;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/** 19 Backup & Recovery + 24 Incident Management over HTTP. */
final class BackupsIncidentsTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    public function testBackupListStartAndDownloadPermissions(): void
    {
        $storage = TestContainer::service(ObjectStorage::class);
        if (!$storage->isConfigured()) {
            self::markTestSkipped('S3 not configured');
        }
        $storage->ensureBucket();
        $db = Fixtures::db();
        $db->createCommand("UPDATE backups SET status = 'failed', error = 'stale (e2e cleanup)' WHERE status = 'running' AND started_at < now() - interval '1 hour'")->execute();
        $backup = (new Query($db))->from('backups')->where(['status' => 'succeeded'])->orderBy(['started_at' => SORT_DESC])->one()
            ?? TestContainer::service(BackupService::class)->run(null, 'e2e');

        [$admin, $user] = AdminSession::login(['superadmin']);
        $r = $admin->get('/admin/backups');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('WAL archiving', $admin->body());
        self::assertStringContainsString('data-backup="' . $backup['id'] . '"', $admin->body());
        self::assertSame(200, $admin->get('/admin/backups/' . $backup['id'])->getStatusCode());

        $r = $admin->get('/admin/backups/' . $backup['id'] . '/download');
        self::assertContains($r->getStatusCode(), [200, 302]);
        if ($r->getStatusCode() === 200) {
            self::assertSame('PGDMP', substr($admin->body(), 0, 5));
            self::assertStringContainsString('attachment', $r->getHeaderLine('Content-Disposition'));
        }
        self::assertTrue((new Query($db))->from('audit_log')->where(['action' => 'backup.downloaded', 'entity_id' => $backup['id'], 'actor_id' => $user['id']])->exists());

        AdminSession::reauth($admin, $user);
        $admin->get('/admin/backups');
        $before = (int) (new Query($db))->from('jobs')->where(['type' => 'platform.backup.run'])->count();
        $r = $admin->post('/admin/backups/start');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Backup queued', $admin->body());
        self::assertSame($before + 1, (int) (new Query($db))->from('jobs')->where(['type' => 'platform.backup.run'])->count());

        // auditor: backup.view only → list yes, download / start / verify no
        [$auditor] = AdminSession::login(['auditor']);
        self::assertSame(200, $auditor->get('/admin/backups')->getStatusCode());
        self::assertSame(403, $auditor->get('/admin/backups/' . $backup['id'] . '/download')->getStatusCode());
        self::assertSame(403, $auditor->post('/admin/backups/start')->getStatusCode());
        self::assertSame(403, $auditor->post('/admin/backups/' . $backup['id'] . '/verify')->getStatusCode());
    }

    public function testIncidentLifecycleOverHttp(): void
    {
        [$web] = AdminSession::login(['devops_sre']);
        $web->get('/admin/incidents');
        $title = 'E2E outage ' . bin2hex(random_bytes(3));
        $r = $web->post('/admin/incidents', ['title' => $title, 'severity' => 'sev3', 'service' => 'api', 'description' => 'Synthetic']);
        self::assertSame(200, $r->getStatusCode());
        self::assertMatchesRegularExpression('#/admin/incidents/[0-9a-f-]{36}$#', $web->url());
        $url = $web->url();
        self::assertStringContainsString($title, $web->body());
        self::assertStringContainsString('data-event="opened"', $web->body());

        $web->post($url . '/acknowledge');
        self::assertStringContainsString('data-event="acknowledged"', $web->body());
        $web->post($url . '/status', ['status' => 'identified', 'note' => 'cause found']);
        $web->post($url . '/notes', ['body' => 'Mitigation deployed']);
        $web->post($url . '/status', ['status' => 'resolved']);
        self::assertStringContainsString('Mitigation deployed', $web->body());
        self::assertStringContainsString('badge--ok">resolved', $web->body());
        $web->post($url . '/status', ['status' => 'closed']); // sev3: no postmortem required
        self::assertStringContainsString('closed', $web->body());

        // validation error re-renders the form with 422
        $web->get('/admin/incidents');
        self::assertSame(422, $web->post('/admin/incidents', ['title' => '', 'severity' => 'sev9'])->getStatusCode());

        [$auditor] = AdminSession::login(['auditor']);
        self::assertSame(200, $auditor->get($url)->getStatusCode());
        self::assertSame(403, $auditor->post('/admin/incidents', ['title' => 'x', 'severity' => 'sev4'])->getStatusCode());
        self::assertSame(403, $auditor->post($url . '/notes', ['body' => 'x'])->getStatusCode());
    }
}
