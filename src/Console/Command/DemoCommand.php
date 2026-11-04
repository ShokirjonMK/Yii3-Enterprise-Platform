<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Environment;
use App\Platform\Access\AccessService;
use App\Platform\Access\RbacCatalog;
use App\Platform\Api\ApiClientService;
use App\Platform\Backup\BackupService;
use App\Platform\Deployments\DeploymentService;
use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Identity\Application\PasswordPolicy;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Identity\Sso\SsoService;
use App\Platform\Incidents\IncidentService;
use App\Platform\Integrations\IntegrationService;
use App\Platform\Integrations\Webhook\WebhookService;
use App\Platform\Messaging\Kafka\KafkaProducer;
use App\Platform\Notifications\Notifier;
use App\Platform\Organizations\OrganizationService;
use App\Platform\Queue\JobQueue;
use App\Platform\Tenancy\TenantService;
use App\Modules\Projects\Application\ProjectService;
use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Demo / test data: 1–2 real records for every Platform Control Center section, created through the
 * real application services (validation, audit, outbox, jobs) — never by inserting fake rows.
 * Idempotent (records are recognised by their "demo" codes). Refuses to run in production.
 *
 * Demo user passwords are random, printed once and stored only in runtime/demo-credentials.txt (0600, git-ignored).
 */
#[AsCommand(name: 'platform:demo', description: 'Create demo data for every admin section (dev/test only, idempotent)')]
final class DemoCommand extends Command
{
    private SymfonyStyle $io;
    /** @var list<array{0:string,1:string,2:string}> */
    private array $report = [];

    public function __construct(
        private readonly ContainerInterface $c,
        private readonly ConnectionInterface $db,
        private readonly UserRepository $users,
        private readonly RequestContext $context,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('skip-backup', null, InputOption::VALUE_NONE, 'Do not run a real backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        if (Environment::isProd()) {
            $this->io->error('platform:demo is disabled in production.');
            return ExitCode::NOPERM;
        }
        $superId = (new Query($this->db))->select('a.user_id')->from(['a' => 'yii_rbac_assignment'])
            ->innerJoin(['u' => 'users'], 'u.id::text = a.user_id')
            ->where(['a.item_name' => RbacCatalog::SUPERADMIN, 'u.status' => 'active', 'u.deleted_at' => null])->scalar();
        if (!$superId) {
            $this->io->error('Run ./yii platform:install first (no active superadmin).');
            return ExitCode::CONFIG;
        }
        $super = $this->users->find((string) $superId);
        $this->context->setChannel('cli');
        $this->context->setUserId($super->id);
        $this->context->setTenantId($super->tenantId);
        $this->io->title('Demo data · Yii3 Enterprise Platform by ShokirjonMK');

        $credentials = [];
        // demo shows everything: switch on the optional sections (fresh installs keep unused ones off)
        $this->step('21 Feature flags (all optional sections on)', function () use ($super) {
            $flags = $this->c->get(\App\Platform\FeatureFlags\FeatureFlagService::class);
            $n = 0;
            foreach (\App\Platform\FeatureFlags\FeatureRegistry::all() as $code => $def) {
                if (str_starts_with($code, 'observability.')) {
                    continue; // tracing needs a collector (monitoring profile)
                }
                if (!$flags->isEnabled($code)) {
                    try {
                        $flags->enable($super->id, $code);
                        $n++;
                    } catch (Throwable $e) {
                        $this->io->warning("$code: " . $e->getMessage()); // e.g. a dependency (Kafka) is not available
                    }
                }
            }
            return "$n enabled";
        });
        $this->step('05 Tenants', fn() => $this->tenants($super));
        $this->step('02 Organizations', fn() => $this->organizations($super));
        $this->step('02 Users', function () use (&$credentials) {
            return $this->demoUsers($credentials);
        });
        $this->step('03 SSO provider (unconfigured OIDC sample)', fn() => $this->sso($super));
        $this->step('06 API client', fn() => $this->apiClient($super));
        $this->step('07 Integration + webhook endpoint', fn() => $this->integrations($super));
        $this->step('23 Projects (+ outbox events, workflow, approval, notifications)', fn() => $this->projects($super));
        $this->step('08 Notifications', fn() => $this->notifications($super));
        $this->step('10 Jobs (report job + failed demo job)', fn() => $this->jobs($super));
        $this->step('11 Kafka dead letter (real consumer failure)', fn() => $this->deadLetter());
        $this->step('14 Stored file (validated upload → S3)', fn() => $this->file($super));
        $this->step('16 Deployment request', fn() => $this->deployment($super));
        $this->step('24 Incident', fn() => $this->incident($super));
        $this->step('21 Feature flag tenant override', fn() => $this->flagOverride($super));
        if (!$input->getOption('skip-backup')) {
            $this->step('19 Backup (real pg_dump → S3)', fn() => $this->backup($super));
        }

        $this->io->table(['Section', 'Result', 'Details'], $this->report);
        if ($credentials !== []) {
            $file = dirname(__DIR__, 3) . '/runtime/demo-credentials.txt';
            $lines = ["# Demo accounts (dev/test only) — generated " . date(DATE_ATOM)];
            foreach ($credentials as [$u, $p, $roles]) {
                $lines[] = "$u\t$p\t$roles";
            }
            file_put_contents($file, implode("\n", $lines) . "\n");
            @chmod($file, 0600);
            $this->io->section('Demo accounts (shown once; also in runtime/demo-credentials.txt)');
            $this->io->table(['Username', 'Password', 'Roles'], $credentials);
        }
        $failed = count(array_filter($this->report, static fn($r) => $r[1] === 'FAILED'));
        $failed === 0 ? $this->io->success('Demo data ready.') : $this->io->warning("$failed step(s) failed — see table.");
        return $failed === 0 ? ExitCode::OK : ExitCode::SOFTWARE;
    }

    private function step(string $name, callable $fn): void
    {
        try {
            $details = $fn();
            $this->report[] = [$name, $details === null ? 'exists' : 'created', is_string($details) ? $details : ''];
        } catch (Throwable $e) {
            $this->report[] = [$name, 'FAILED', mb_strimwidth($e->getMessage(), 0, 120, '…')];
        }
    }

    private function exists(string $table, array $where): bool
    {
        return (new Query($this->db))->from($table)->where($where)->exists();
    }

    private function tenants(User $super): ?string
    {
        if ($this->exists('tenants', ['code' => 'demo-region'])) {
            return null;
        }
        $svc = $this->c->get(TenantService::class);
        $svc->create($super, ['code' => 'demo-region', 'name' => 'Demo Regional Office', 'domain' => 'demo-region.local']);
        $svc->create($super, ['code' => 'demo-branch', 'name' => 'Demo Branch']);
        return 'demo-region, demo-branch';
    }

    private function organizations(User $super): ?string
    {
        if ($this->exists('organizations', ['code' => 'demo-hq'])) {
            return null;
        }
        $svc = $this->c->get(OrganizationService::class);
        $hq = $svc->create($super, ['tenant_id' => $super->tenantId, 'code' => 'demo-hq', 'name' => 'Demo Headquarters', 'type' => 'company', 'inn' => '123456789']);
        $svc->create($super, ['tenant_id' => $super->tenantId, 'code' => 'demo-it', 'name' => 'IT Department', 'type' => 'department', 'parent_id' => $hq['id']]);
        return 'HQ → IT Department';
    }

    private function demoUsers(array &$credentials): ?string
    {
        $created = [];
        $access = $this->c->get(AccessService::class);
        $hasher = $this->c->get(PasswordHasher::class);
        $org = (new Query($this->db))->select('id')->from('organizations')->where(['code' => 'demo-it'])->scalar() ?: null;
        $tenant = (new Query($this->db))->select('id')->from('tenants')->where(['code' => 'default'])->scalar() ?: null;
        foreach ([['demo.manager', 'Demo Manager', ['business_admin']], ['demo.user', 'Demo User', ['user']]] as [$username, $name, $roles]) {
            if ($this->users->findByLogin($username) !== null) {
                continue;
            }
            $password = PasswordPolicy::generate(16);
            $id = $this->users->insert([
                'username' => $username, 'email' => $username . '@demo.local', 'full_name' => $name,
                'password_hash' => $hasher->hash($password), 'password_changed_at' => new Expression('now()'),
                'tenant_id' => $tenant, 'organization_id' => $org,
            ]);
            $access->assignSystem($id, 'user');
            foreach ($roles as $r) {
                $access->assignSystem($id, $r);
            }
            $credentials[] = [$username, $password, implode(',', $roles)];
            $created[] = $username;
        }
        return $created === [] ? null : implode(', ', $created);
    }

    private function sso(User $super): ?string
    {
        if ($this->exists('sso_providers', ['code' => 'demo-corp-oidc'])) {
            return null;
        }
        $this->c->get(SsoService::class)->save($super->id, null, [
            'type' => 'oidc', 'code' => 'demo-corp-oidc', 'name' => 'Demo Corporate SSO (configure issuer to enable)',
            'config' => ['scopes' => 'openid profile email'], 'default_role' => 'user',
        ]);
        return 'status: unconfigured (honest — no IdP credentials)';
    }

    private function apiClient(User $super): ?string
    {
        if ($this->exists('api_clients', ['name' => 'Demo integration client'])) {
            return null;
        }
        $r = $this->c->get(ApiClientService::class)->create($super, ['name' => 'Demo integration client', 'scopes' => 'projects.read', 'rate_limit_per_minute' => 120, 'tenant_id' => $super->tenantId]);
        return 'client_id ' . ($r['client']['client_id'] ?? $r['client_id'] ?? '?') . ' (secret shown only in admin at creation)';
    }

    private function integrations(User $super): ?string
    {
        $out = [];
        if (!$this->exists('integrations', ['code' => 'demo-httpbin'])) {
            $this->c->get(IntegrationService::class)->create($super->id, [
                'code' => 'demo-httpbin', 'name' => 'Demo HTTP echo service', 'base_url' => 'https://httpbin.org',
                'auth_type' => 'none', 'timeout_ms' => 5000, 'health_path' => '/status/200',
            ]);
            $out[] = 'integration demo-httpbin (disabled until tested)';
        }
        if (!$this->exists('webhook_endpoints', ['code' => 'demo-hook'])) {
            $this->c->get(WebhookService::class)->create($super->id, ['code' => 'demo-hook', 'name' => 'Demo inbound webhook', 'enabled' => '1', 'tolerance_seconds' => 300]);
            $out[] = 'webhook POST /webhooks/demo-hook';
        }
        return $out === [] ? null : implode('; ', $out);
    }

    private function projects(User $super): ?string
    {
        if ($this->exists('projects', ['code' => 'DEMO-ALPHA'])) {
            return null;
        }
        $svc = $this->c->get(ProjectService::class);
        $manager = $this->users->findByLogin('demo.manager') ?? $super;
        $alpha = $svc->create($super, ['code' => 'DEMO-ALPHA', 'name' => 'Alpha — e-services portal', 'description' => 'Demo project: citizen e-services portal.', 'priority' => 'high', 'budget' => '150000.00', 'starts_on' => date('Y-m-d'), 'due_on' => date('Y-m-d', strtotime('+90 days')), 'tags' => ['demo', 'portal'], 'owner_id' => $manager->id]);
        $svc->changeStatus($super, $alpha->id, 'active', null, 'Kick-off');
        $beta = $svc->create($super, ['code' => 'DEMO-BETA', 'name' => 'Beta — data migration', 'description' => 'Demo project: legacy data migration.', 'priority' => 'normal', 'starts_on' => date('Y-m-d', strtotime('-60 days')), 'due_on' => date('Y-m-d'), 'tags' => ['demo'], 'owner_id' => $super->id]);
        $svc->changeStatus($super, $beta->id, 'active', null, 'Start');
        $beta = $svc->changeStatus($super, $beta->id, 'completed', null, 'Delivered');
        try {
            $svc->archive($super, $beta->id, null, 'Close out — needs approval');
        } catch (Throwable) {
            // archive may be approval-gated (202) — the pending approval is the demo record
        }
        return 'DEMO-ALPHA (active), DEMO-BETA (completed, archive approval pending)';
    }

    private function notifications(User $super): ?string
    {
        $notifier = $this->c->get(Notifier::class);
        $n = 0;
        foreach (['demo.manager', 'demo.user'] as $u) {
            $user = $this->users->findByLogin($u);
            if ($user !== null && !$this->exists('notifications', ['user_id' => $user->id, 'template_code' => 'security.new_login'])) {
                $n += count($notifier->notify($user->id, 'security.new_login', ['ip' => '203.0.113.10', 'time' => date('Y-m-d H:i')], ['inapp', 'email'], 'security'));
            }
        }
        return $n === 0 ? null : "$n notifications (in-app + e-mail via Mailpit)";
    }

    private function jobs(User $super): ?string
    {
        $queue = $this->c->get(JobQueue::class);
        $out = [];
        if (!$this->exists('jobs', ['type' => 'projects.report.generate', 'status' => ['pending', 'running', 'succeeded']])) {
            $r = $this->c->get(ProjectService::class)->requestReport($super);
            $out[] = 'projects CSV report job queued (' . ($r['job_id'] ?? $r['id'] ?? 'ok') . ')';
        }
        if (!$this->exists('jobs', ['idempotency_key' => 'demo:failing'])) {
            // A notification delivery for a missing notification fails permanently → shows up under failed/dead jobs
            $queue->push('platform.notifications.deliver', ['notification_id' => '00000000-0000-0000-0000-000000000000'], ['queue' => 'notifications', 'max_attempts' => 1, 'idempotency_key' => 'demo:failing']);
            $out[] = 'one permanently failing job (dead-letter demo)';
        }
        return $out === [] ? null : implode('; ', $out);
    }

    private function deadLetter(): ?string
    {
        if ($this->exists('kafka_dead_letters', ['message_key' => 'demo-poison'])) {
            return null;
        }
        // Unparseable message on a consumed topic → the running projects consumer moves it to the DLQ
        $this->c->get(KafkaProducer::class)->publish('projects.events', 'demo-poison', 'not-a-json-envelope', ['event_type' => 'demo.poison']);
        return 'poison message published to projects.events (consumer-projects → DLQ within seconds)';
    }

    private function file(User $super): ?string
    {
        if ($this->exists('stored_files', ['original_name' => 'demo-readme.txt', 'deleted_at' => null])) {
            return null;
        }
        $storage = $this->c->get(\App\Platform\Storage\FileStorageService::class);
        $tmp = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($tmp, "Yii3 Enterprise Platform demo file
Built by ShokirjonMK
");
        try {
            $valid = $storage->validator()->validateFile($tmp, 'demo-readme.txt');
            $row = $storage->storeValidated($valid, $super->id, $super->tenantId, 'demo', 'readme');
        } finally {
            @unlink($tmp);
        }
        return 'demo-readme.txt (' . ($row['size_bytes'] ?? '?') . ' bytes, sha256 ' . substr((string) ($row['checksum_sha256'] ?? ''), 0, 12) . '…)';
    }

    private function deployment(User $super): ?string
    {
        if ($this->exists('deployments', ['version' => '1.0.0-demo']) || $this->exists('deployments', ['environment' => 'staging', 'status' => ['requested', 'approved', 'running']])) {
            return null;
        }
        $this->c->get(DeploymentService::class)->request($super, ['environment' => 'staging', 'version' => '1.0.0-demo', 'commit_sha' => substr(hash('sha1', 'demo'), 0, 12), 'strategy' => 'rolling', 'notes' => 'Demo deployment request (needs a second approver — four-eyes)']);
        return 'staging 1.0.0-demo (requested)';
    }

    private function incident(User $super): ?string
    {
        if ($this->exists('incidents', ['title' => 'Demo: elevated API latency'])) {
            return null;
        }
        $svc = $this->c->get(IncidentService::class);
        $id = $svc->open($super, ['title' => 'Demo: elevated API latency', 'severity' => 'sev4', 'service' => 'api', 'description' => 'Demo incident for the Incident Management section.']);
        $svc->acknowledge($super, $id);
        $svc->addNote($super, $id, 'Investigating slow queries (demo note).');
        return 'sev4 incident, acknowledged, 1 note';
    }

    private function flagOverride(User $super): ?string
    {
        $tenant = (new Query($this->db))->select('id')->from('tenants')->where(['code' => 'demo-branch'])->scalar();
        if (!$tenant || $this->exists('feature_flag_overrides', ['tenant_id' => $tenant])) {
            return null;
        }
        $this->c->get(FeatureFlagService::class)->setTenantOverride($super->id, 'module.projects', (string) $tenant, false);
        return 'module.projects disabled for demo-branch';
    }

    private function backup(User $super): ?string
    {
        if ($this->exists('backups', ['status' => 'succeeded'])) {
            return null;
        }
        $r = $this->c->get(BackupService::class)->run($super->id, 'demo');
        return 'backup ' . ($r['id'] ?? '') . ' ' . ($r['status'] ?? '');
    }
}
