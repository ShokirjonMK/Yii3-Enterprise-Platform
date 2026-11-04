<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Environment;
use App\Platform\Access\AccessService;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RbacSynchronizer;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Application\PasswordPolicy;
use App\Platform\Identity\Domain\AuthPolicy;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Monitoring\HealthChecker;
use App\Platform\Setup\PlatformSeeder;
use App\Platform\Storage\ObjectStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Idempotent installer / upgrader. Safe to run repeatedly (also from CI/CD before rollout):
 *   1. environment checks   2. database connection   3. migrations   4. RBAC sync
 *   5. reference data       6. initial superadmin (only if none exists)   7. health check
 */
#[AsCommand(name: 'platform:install', description: 'Install or upgrade the platform (idempotent)')]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RbacSynchronizer $rbacSync,
        private readonly PlatformSeeder $seeder,
        private readonly UserRepository $users,
        private readonly AccessService $access,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly HealthChecker $health,
        private readonly AuditLogger $audit,
        private readonly ObjectStorage $storage,
        private readonly array $platform,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin-username', null, InputOption::VALUE_REQUIRED, 'Initial superadmin username (asked interactively if omitted)')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Initial superadmin e-mail')
            ->addOption('admin-password-stdin', null, InputOption::VALUE_NONE, 'Read the initial password from STDIN instead of generating one')
            ->addOption('skip-migrations', null, InputOption::VALUE_NONE, 'Do not run migrations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Yii3 Enterprise Platform — install / upgrade');

        // 1. Environment
        $io->section('1/7 Environment');
        $problems = $this->environmentProblems();
        foreach ($problems['errors'] as $p) {
            $io->error($p);
        }
        foreach ($problems['warnings'] as $p) {
            $io->warning($p);
        }
        if ($problems['errors'] !== []) {
            return ExitCode::CONFIG;
        }
        $io->writeln(sprintf(' PHP %s, APP_ENV=%s, debug=%s', PHP_VERSION, Environment::appEnv(), Environment::appDebug() ? 'on' : 'off'));

        // 2. Database
        $io->section('2/7 Database connection');
        try {
            $version = $this->db->createCommand('SHOW server_version')->queryScalar();
            $io->writeln(" PostgreSQL $version at {$this->platform['db']['host']}:{$this->platform['db']['port']}/{$this->platform['db']['name']}");
        } catch (Throwable $e) {
            $io->error('Cannot connect to PostgreSQL: ' . $e->getMessage());
            return ExitCode::UNAVAILABLE;
        }

        // 3. Migrations
        $io->section('3/7 Migrations');
        if ($input->getOption('skip-migrations')) {
            $io->writeln(' skipped');
        } else {
            $migrate = $this->getApplication()?->find('migrate:up');
            $code = $migrate?->run(new ArrayInput(['--no-interaction' => true]), $output) ?? 1;
            if ($code !== 0) {
                $io->error('Migrations failed.');
                return ExitCode::SOFTWARE;
            }
        }

        // 4. RBAC
        $io->section('4/7 Roles & permissions');
        $stats = $this->rbacSync->sync();
        $io->writeln(sprintf(' permissions +%d, roles +%d, links +%d/-%d', $stats['permissions_created'], $stats['roles_created'], $stats['links_added'], $stats['links_removed']));

        // 5. Reference data
        $io->section('5/7 Reference data');
        $seed = $this->seeder->seed();
        $io->writeln(' ' . implode(', ', array_map(static fn($k, $v) => "$k +$v", array_keys($seed), $seed)));
        if ($this->storage->isConfigured()) {
            try {
                $this->storage->ensureBucket();
                $io->writeln(' object storage bucket "' . $this->storage->bucket() . '" ready');
            } catch (Throwable $e) {
                $io->warning('Object storage not reachable: ' . $e->getMessage());
            }
        }

        // 6. Superadmin
        $io->section('6/7 Superadmin');
        $existing = $this->users->countActiveWithRole(RbacCatalog::SUPERADMIN);
        if ($existing > 0) {
            $io->writeln(" $existing active superadmin(s) exist — bootstrap skipped (it can run only once).");
        } else {
            $result = $this->createSuperadmin($input, $io);
            if ($result !== ExitCode::OK) {
                return $result;
            }
        }

        // 7. Health
        $io->section('7/7 Health check');
        $results = $this->health->checkAll();
        $rows = [];
        foreach ($results as $name => $r) {
            $rows[] = [$name, strtoupper($r['status']), $r['latency_ms'] . ' ms', json_encode($r['details'], JSON_UNESCAPED_SLASHES)];
        }
        $io->table(['component', 'status', 'latency', 'details'], $rows);
        $overall = HealthChecker::overall($results);

        $this->db->createCommand(
            "INSERT INTO settings (key, scope, value) VALUES ('platform.installed_at', 'global', to_jsonb(now()::text))
             ON CONFLICT (key, scope, COALESCE(scope_id, '00000000-0000-0000-0000-000000000000'::uuid)) DO NOTHING",
        )->execute();
        $this->audit->record('platform.installed', 'platform', null, null, ['health' => $overall], ['seed' => $seed, 'rbac' => $stats], category: 'system', severity: 'notice');

        if ($overall === HealthChecker::DOWN) {
            $io->error('Installation finished but critical dependencies are DOWN.');
            return ExitCode::UNAVAILABLE;
        }
        $io->success('Platform is installed. Overall health: ' . strtoupper($overall));
        return ExitCode::OK;
    }

    private function createSuperadmin(InputInterface $input, SymfonyStyle $io): int
    {
        $username = $input->getOption('admin-username');
        if ($username === null && $input->isInteractive()) {
            $username = $io->ask('Superadmin username', null, static function (?string $v): string {
                if ($v === null || !preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $v)) {
                    throw new \RuntimeException('3–64 characters: letters, digits, . _ @ -');
                }
                return $v;
            });
        }
        if ($username === null) {
            $io->error('No superadmin exists. Pass --admin-username (non-interactive mode).');
            return ExitCode::USAGE;
        }
        $email = $input->getOption('admin-email');
        if ($email === null && $input->isInteractive()) {
            $email = $io->ask('Superadmin e-mail (optional)', '') ?: null;
        }

        $generated = false;
        if ($input->getOption('admin-password-stdin')) {
            $password = trim((string) stream_get_contents(STDIN));
        } elseif ($input->isInteractive() && $io->confirm('Enter the password yourself? (No = generate a strong temporary password)', false)) {
            $q = new Question('Password');
            $q->setHidden(true)->setHiddenFallback(false);
            $password = (string) $io->askQuestion($q);
        } else {
            $password = PasswordPolicy::generate(24);
            $generated = true;
        }
        $errors = $this->policy->violations($password, (string) $username);
        if ($errors !== []) {
            $io->error($errors);
            return ExitCode::DATAERR;
        }

        $tenantId = (new Query($this->db))->select('id')->from('tenants')->where(['code' => PlatformSeeder::DEFAULT_TENANT])->scalar();
        $hash = $this->hasher->hash($password);
        $id = $this->db->transaction(function () use ($username, $email, $hash, $tenantId): string {
            $id = $this->users->insert([
                'username' => $username,
                'email' => $email,
                'full_name' => 'Super Administrator',
                'password_hash' => $hash,
                'password_changed_at' => new Expression('now()'),
                'auth_policy' => AuthPolicy::ANY,
                'must_change_password' => true,
                'mfa_required' => true,
                'tenant_id' => $tenantId ?: null,
            ]);
            $this->users->addPasswordHistory($id, $hash);
            $this->access->assignSystem($id, 'user');
            $this->access->assignSystem($id, RbacCatalog::SUPERADMIN);
            $this->audit->record('platform.superadmin.bootstrapped', 'user', $id, null, ['username' => $username], category: 'security', severity: 'warning');
            return $id;
        });

        $io->writeln(" Superadmin <info>$username</info> created (id $id).");
        if ($generated) {
            $io->block([
                'TEMPORARY PASSWORD (shown once, not stored anywhere in plain text):',
                $password,
                'You will be required to change it and to set up MFA on first login.',
            ], null, 'fg=black;bg=yellow', ' ', true);
        } else {
            $io->writeln(' Password change and MFA setup will be required on first login.');
        }
        return ExitCode::OK;
    }

    /** @return array{errors:list<string>, warnings:list<string>} */
    private function environmentProblems(): array
    {
        $errors = [];
        $warnings = [];
        if (PHP_VERSION_ID < 80300) {
            $errors[] = 'PHP 8.3+ is required.';
        }
        foreach (['pdo_pgsql', 'sodium', 'intl', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "PHP extension $ext is missing.";
            }
        }
        if (!defined('PASSWORD_ARGON2ID')) {
            $errors[] = 'PHP is built without Argon2id support.';
        }
        if (strlen((string) $this->platform['secret']) < 32) {
            $errors[] = 'APP_SECRET must be set (≥ 32 characters).';
        }
        if (Environment::isProd()) {
            if (Environment::bool('APP_DEBUG')) {
                $errors[] = 'APP_DEBUG must be false in production.';
            }
            $host = (string) parse_url((string) $this->platform['url'], PHP_URL_HOST);
            if (!str_starts_with((string) $this->platform['url'], 'https://')) {
                if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    $warnings[] = 'APP_URL is plain http on localhost (local fast mode) — never expose this setup.';
                } else {
                    $errors[] = 'APP_URL must use https:// in production.';
                }
            }
            if (ini_get('expose_php')) {
                $warnings[] = 'expose_php should be Off.';
            }
        }
        if (!extension_loaded('rdkafka')) {
            $warnings[] = 'ext-rdkafka is not installed: Kafka features will be unavailable.';
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
