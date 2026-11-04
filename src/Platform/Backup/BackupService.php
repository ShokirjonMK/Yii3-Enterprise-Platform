<?php

declare(strict_types=1);

namespace App\Platform\Backup;

use App\Environment;
use App\Platform\Audit\AuditLogger;
use App\Platform\Settings\SettingsService;
use App\Platform\Storage\ObjectStorage;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Logging\Redactor;
use Aws\S3\S3Client;
use DateTimeImmutable;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * 19 · Logical backups and restore verification.
 *
 * Backup:  REPEATABLE READ snapshot → exact row counts of key tables → `pg_dump -Fc --snapshot=<id>`
 *          (same snapshot, so the counts describe exactly what is inside the dump) → sha256 → upload to
 *          S3 `backups/<YYYY-MM-DD>/<db>-<timestamp>-<id>.dump` → optional copy to the off-site bucket.
 * Verify:  download → checksum check → CREATE DATABASE verify_<timestamp> → `pg_restore --single-transaction
 *          --exit-on-error` → compare row counts with the snapshot → DROP DATABASE → result in verify_details.
 *
 * Credentials are passed to pg_dump/pg_restore through a PGPASSFILE (0600 temp file, deleted
 * afterwards) — never on the command line or in the process environment.
 *
 * Logical dumps complement, not replace, physical backups + WAL archiving for PITR (docs/BACKUP_RECOVERY.md).
 */
final class BackupService
{
    public const KEY_TABLES = [
        'tenants', 'organizations', 'users', 'user_identities', 'user_sessions', 'api_tokens', 'api_clients',
        'yii_rbac_item', 'yii_rbac_item_child', 'yii_rbac_assignment', 'settings', 'setting_versions', 'feature_flags',
        'schedules', 'notification_templates', 'notifications', 'workflows', 'approval_requests', 'incidents',
        'incident_events', 'deployments', 'stored_files', 'jobs', 'outbox', 'audit_log',
    ];
    public const SORTABLE = ['started_at' => 'b.started_at', 'size' => 'b.size_bytes', 'status' => 'b.status', 'verified_at' => 'b.verified_at'];
    public const PREFIX = 'backups/';

    /**
     * @param array{host:string, port:string, name:string, user:string, password:string, sslmode:string} $dbConfig
     * @param array{endpoint:?string, region:string, bucket:string, key:string, secret:string, pathStyle:bool} $s3Config
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ObjectStorage $storage,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly array $dbConfig,
        private readonly array $s3Config = [],
        private readonly int $timeoutSeconds = 3600,
        private readonly string $pgDump = 'pg_dump',
        private readonly string $pgRestore = 'pg_restore',
    ) {}

    public function list(int $page, ?string $sort, int $perPage = 20): Page
    {
        $q = (new Query($this->db))->select(['b.*', 'initiator' => 'u.username'])->from(['b' => 'backups'])->leftJoin(['u' => 'users'], 'u.id = b.initiated_by');
        return Paginator::paginate($q, $page, $perPage, $sort, self::SORTABLE, '-started_at');
    }

    public function get(string $id): array
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            throw new NotFoundException('Backup not found.');
        }
        $row = (new Query($this->db))->from('backups')->where(['id' => $id])->one();
        if ($row === null) {
            throw new NotFoundException('Backup not found.');
        }
        $row['verify_details'] = Sql::json($row['verify_details'], null);
        return $row;
    }

    /** @return array<string,mixed> summary for KPI tiles */
    public function summary(): array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT (SELECT max(finished_at) FROM backups WHERE status = 'succeeded') AS last_success,
                   (SELECT max(verified_at) FROM backups WHERE verify_status = 'passed') AS last_verified,
                   (SELECT count(*) FROM backups WHERE status = 'succeeded') AS available,
                   (SELECT COALESCE(sum(size_bytes), 0) FROM backups WHERE status = 'succeeded') AS total_bytes,
                   (SELECT count(*) FROM backups WHERE status = 'failed' AND started_at > now() - interval '7 days') AS failed_7d,
                   (SELECT count(*) FROM backups WHERE status = 'running') AS running
            SQL)->queryOne() ?: [];
        $row['retention_days'] = (int) $this->settings->get('retention.backups_days');
        $row['offsite_bucket'] = (string) $this->settings->get('ops.backup_offsite_bucket');
        $row['storage_configured'] = $this->storage->isConfigured();
        $row['bucket'] = $this->storage->bucket();
        return $row;
    }

    /**
     * Runs a logical backup synchronously (called by the `platform.backup.run` job).
     *
     * @param (callable(int):void)|null $progress
     * @return array<string,mixed> the backup row
     */
    public function run(?string $actorId = null, string $trigger = 'manual', ?string $jobId = null, ?callable $progress = null): array
    {
        if (!$this->storage->isConfigured()) {
            throw new RuntimeException('Object storage (S3) is not configured — backups need S3_ENDPOINT/S3_KEY/S3_SECRET.');
        }
        $running = (new Query($this->db))->from('backups')->where(['status' => 'running'])->andWhere(['>', 'started_at', new Expression("now() - interval '6 hours'")])->exists();
        if ($running) {
            throw new ConflictException('Another backup is already running.');
        }
        $dbName = (string) $this->dbConfig['name'];
        $id = (string) $this->db->createCommand()->insertReturningPks('backups', [
            'type' => 'logical', 'status' => 'running', 'initiated_by' => $actorId, 'database_name' => $dbName, 'job_id' => $jobId,
        ])['id'];
        $started = microtime(true);
        $file = null;
        $passFile = null;
        try {
            $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $key = self::PREFIX . $now->format('Y-m-d') . '/' . $dbName . '-' . $now->format('Ymd\THis\Z') . '-' . substr($id, 0, 8) . '.dump';
            $file = $this->tempFile('dump');
            $passFile = $this->passFile();

            // 1+2. Consistent snapshot: counts and dump see exactly the same data
            $tx = $this->db->beginTransaction();
            try {
                $this->db->createCommand('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY')->execute();
                $snapshot = (string) $this->db->createCommand('SELECT pg_export_snapshot()')->queryScalar();
                $counts = $this->countRows($this->db->getActivePdo());
                $progress && $progress(10);
                $this->exec([$this->pgDump, '--format=custom', '--compress=6', '--no-password', '--snapshot=' . $snapshot,
                    '--host=' . $this->dbConfig['host'], '--port=' . $this->dbConfig['port'], '--username=' . $this->dbConfig['user'],
                    '--file=' . $file, '--dbname=' . $dbName], $passFile);
            } finally {
                $tx->commit();
            }
            $progress && $progress(60);

            // 3. Checksum + upload
            $checksum = (string) hash_file('sha256', $file);
            $size = (int) filesize($file);
            $stream = fopen($file, 'rb');
            try {
                $this->storage->filesystem()->writeStream($key, $stream, ['ContentType' => 'application/octet-stream', 'Metadata' => ['sha256' => $checksum, 'backup-id' => $id]]);
            } finally {
                is_resource($stream) && fclose($stream);
            }
            $progress && $progress(85);

            // 4. Optional off-site copy (server-side copy into another bucket of the same S3 account)
            $offsite = null;
            $offsiteBucket = trim((string) $this->settings->get('ops.backup_offsite_bucket'));
            $offsiteError = null;
            if ($offsiteBucket !== '') {
                try {
                    $this->storage->client()->copyObject(['Bucket' => $offsiteBucket, 'Key' => $key, 'CopySource' => $this->storage->bucket() . '/' . $key]);
                    $offsite = 's3://' . $offsiteBucket . '/' . $key;
                } catch (Throwable $e) {
                    $offsiteError = Redactor::redactString($e->getMessage());
                    $this->logger->error('Off-site backup copy failed', ['backup' => $id, 'error' => $offsiteError]);
                }
            }

            $retention = max(1, (int) $this->settings->get('retention.backups_days'));
            $details = ['source_counts' => $counts, 'snapshot' => $snapshot, 'trigger' => $trigger, 'key' => $key,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'pg_dump' => $this->toolVersion($this->pgDump)];
            if ($offsiteError !== null) {
                $details['offsite_error'] = mb_substr($offsiteError, 0, 500);
            }
            $this->db->createCommand()->update('backups', [
                'status' => 'succeeded',
                'location' => 's3://' . $this->storage->bucket() . '/' . $key,
                'offsite_location' => $offsite,
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'finished_at' => new Expression('now()'),
                'expires_at' => new Expression("now() + make_interval(days => $retention)"),
                'verify_status' => 'pending',
                'verify_details' => json_encode($details, JSON_UNESCAPED_SLASHES),
            ], ['id' => $id])->execute();
            $this->audit->record('backup.completed', 'backup', $id, null, ['status' => 'succeeded', 'size_bytes' => $size, 'checksum_sha256' => $checksum],
                ['trigger' => $trigger, 'location' => $key, 'offsite' => $offsite, 'tables' => count($counts)],
                category: 'ops', severity: 'notice', actorId: $actorId);
            $progress && $progress(100);
        } catch (Throwable $e) {
            $error = mb_substr(Redactor::redactString($e->getMessage()), 0, 2000);
            $this->db->createCommand()->update('backups', ['status' => 'failed', 'error' => $error, 'finished_at' => new Expression('now()')], ['id' => $id])->execute();
            $this->audit->record('backup.completed', 'backup', $id, null, ['status' => 'failed'], ['trigger' => $trigger, 'error' => $error],
                result: 'failure', category: 'ops', severity: 'warning', actorId: $actorId);
            throw $e;
        } finally {
            $file !== null && @unlink($file);
            $passFile !== null && @unlink($passFile);
        }
        return $this->get($id);
    }

    /**
     * Restore test of one backup into a temporary database (always dropped afterwards).
     *
     * @return array<string,mixed> verify_details
     */
    public function verify(string $id, ?string $actorId = null): array
    {
        $backup = $this->get($id);
        if ($backup['status'] !== 'succeeded') {
            throw new ConflictException('Only a successful backup can be verified.');
        }
        $details = is_array($backup['verify_details']) ? $backup['verify_details'] : [];
        $source = (array) ($details['source_counts'] ?? []);
        $key = (string) ($details['key'] ?? self::keyFromLocation((string) $backup['location']));
        $started = microtime(true);
        $verifyDb = 'verify_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(2));
        $file = $this->tempFile('restore');
        $passFile = $this->passFile();
        $created = false;
        $result = ['database' => $verifyDb, 'checked_at' => gmdate(DATE_ATOM)];
        try {
            $in = $this->storage->filesystem()->readStream($key);
            $out = fopen($file, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            is_resource($in) && fclose($in);
            $checksum = (string) hash_file('sha256', $file);
            $result['checksum_ok'] = hash_equals((string) $backup['checksum_sha256'], $checksum);
            if (!$result['checksum_ok']) {
                throw new RuntimeException('Checksum mismatch: the stored object does not match the recorded sha256.');
            }
            $this->db->createCommand('CREATE DATABASE ' . $this->db->getQuoter()->quoteTableName($verifyDb))->execute();
            $created = true;
            $restore = $this->exec([$this->pgRestore, '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error', '--no-password',
                '--host=' . $this->dbConfig['host'], '--port=' . $this->dbConfig['port'], '--username=' . $this->dbConfig['user'],
                '--dbname=' . $verifyDb, $file], $passFile);
            $result['pg_restore_warnings'] = mb_substr(trim($restore), 0, 1000);

            $pdo = $this->connect($verifyDb);
            $restored = $this->countRows($pdo);
            $pdo = null;
            $mismatches = [];
            foreach ($source as $table => $count) {
                if (!array_key_exists($table, $restored) || (int) $restored[$table] !== (int) $count) {
                    $mismatches[$table] = ['source' => (int) $count, 'restored' => $restored[$table] ?? null];
                }
            }
            $result += ['restored_counts' => $restored, 'mismatches' => $mismatches, 'tables_compared' => count($source)];
            $result['passed'] = $source !== [] && $mismatches === [];
            if ($source === []) {
                $result['error'] = 'The backup has no source row-count snapshot to compare with.';
            }
        } catch (Throwable $e) {
            $result['passed'] = false;
            $result['error'] = mb_substr(Redactor::redactString($e->getMessage()), 0, 2000);
        } finally {
            if ($created) {
                try {
                    $this->db->createCommand('DROP DATABASE IF EXISTS ' . $this->db->getQuoter()->quoteTableName($verifyDb) . ' WITH (FORCE)')->execute();
                    $result['dropped'] = true;
                } catch (Throwable $e) {
                    $result['dropped'] = false;
                    $this->logger->error('Verification database could not be dropped', ['database' => $verifyDb, 'exception' => $e->getMessage()]);
                }
            }
            @unlink($file);
            @unlink($passFile);
        }
        $result['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
        $details['verification'] = $result;
        $this->db->createCommand()->update('backups', [
            'verify_status' => $result['passed'] ? 'passed' : 'failed',
            'verified_at' => new Expression('now()'),
            'verify_details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ], ['id' => $id])->execute();
        $this->audit->record('backup.verified', 'backup', $id, null, ['verify_status' => $result['passed'] ? 'passed' : 'failed'],
            ['database' => $verifyDb, 'tables' => $result['tables_compared'] ?? 0, 'mismatches' => array_keys($result['mismatches'] ?? []), 'error' => $result['error'] ?? null],
            result: $result['passed'] ? 'success' : 'failure', category: 'ops', severity: $result['passed'] ? 'info' : 'warning', actorId: $actorId);
        return $result;
    }

    public function verifyLatest(?string $actorId = null): ?array
    {
        $id = (new Query($this->db))->select('id')->from('backups')->where(['status' => 'succeeded'])->orderBy(['started_at' => SORT_DESC])->limit(1)->scalar();
        return $id === false || $id === null ? null : $this->verify((string) $id, $actorId);
    }

    /** Applies retention.backups_days: deletes expired objects (primary + off-site) and marks rows expired. */
    public function purgeExpired(): int
    {
        $n = 0;
        foreach ((new Query($this->db))->from('backups')->where(['status' => 'succeeded'])->andWhere(['<', 'expires_at', new Expression('now()')])->all() as $b) {
            try {
                $key = self::keyFromLocation((string) $b['location']);
                if ($key !== '' && $this->storage->filesystem()->fileExists($key)) {
                    $this->storage->filesystem()->delete($key);
                }
                if ($b['offsite_location']) {
                    [$bucket, $okey] = self::bucketAndKey((string) $b['offsite_location']);
                    $this->storage->client()->deleteObject(['Bucket' => $bucket, 'Key' => $okey]);
                }
                $this->db->createCommand()->update('backups', ['status' => 'expired'], ['id' => $b['id']])->execute();
                $this->audit->record('backup.expired', 'backup', (string) $b['id'], ['status' => 'succeeded'], ['status' => 'expired'], ['location' => $b['location']], category: 'ops');
                $n++;
            } catch (Throwable $e) {
                $this->logger->error('Backup retention failed', ['backup' => $b['id'], 'exception' => $e->getMessage()]);
            }
        }
        return $n;
    }

    /**
     * Download of a backup object: a short-lived pre-signed URL when S3_PUBLIC_ENDPOINT is set
     * (browser-reachable endpoint), otherwise a stream proxied through the application.
     *
     * @return array{type:'redirect', url:string}|array{type:'stream', stream:resource, filename:string, size:?int}
     */
    public function download(string $id, int $ttlSeconds = 300): array
    {
        $b = $this->get($id);
        if ($b['status'] !== 'succeeded') {
            throw new ConflictException('This backup is not available for download.');
        }
        $key = self::keyFromLocation((string) $b['location']);
        $public = Environment::nullableString('S3_PUBLIC_ENDPOINT');
        if ($public !== null && ($this->s3Config['key'] ?? '') !== '') {
            $client = new S3Client([
                'version' => 'latest', 'region' => $this->s3Config['region'] ?? 'us-east-1', 'endpoint' => $public,
                'use_path_style_endpoint' => (bool) ($this->s3Config['pathStyle'] ?? true),
                'credentials' => ['key' => $this->s3Config['key'], 'secret' => $this->s3Config['secret']],
            ]);
            $cmd = $client->getCommand('GetObject', ['Bucket' => $this->storage->bucket(), 'Key' => $key, 'ResponseContentDisposition' => 'attachment; filename="' . basename($key) . '"']);
            return ['type' => 'redirect', 'url' => (string) $client->createPresignedRequest($cmd, '+' . max(30, min(900, $ttlSeconds)) . ' seconds')->getUri()];
        }
        return ['type' => 'stream', 'stream' => $this->storage->filesystem()->readStream($key), 'filename' => basename($key), 'size' => $b['size_bytes'] === null ? null : (int) $b['size_bytes']];
    }

    /** WAL archiving status (continuous archiving is what enables PITR). */
    public function walStatus(): array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT a.archived_count, a.last_archived_wal, a.last_archived_time, a.failed_count, a.last_failed_wal, a.last_failed_time, a.stats_reset,
                   current_setting('archive_mode') AS archive_mode, current_setting('wal_level') AS wal_level,
                   current_setting('archive_timeout') AS archive_timeout, current_setting('archive_command') AS archive_command,
                   current_setting('archive_library') AS archive_library,
                   pg_is_in_recovery() AS in_recovery,
                   CASE WHEN pg_is_in_recovery() THEN NULL ELSE pg_walfile_name(pg_current_wal_lsn()) END AS current_wal,
                   CASE WHEN pg_is_in_recovery() THEN NULL ELSE pg_current_wal_lsn()::text END AS current_lsn,
                   EXTRACT(EPOCH FROM now() - a.last_archived_time)::int AS seconds_since_archive
            FROM pg_stat_archiver a
            SQL)->queryOne() ?: [];
        $row['healthy'] = ($row['archive_mode'] ?? 'off') !== 'off'
            && (int) ($row['archived_count'] ?? 0) > 0
            && ($row['last_failed_time'] === null || ($row['last_archived_time'] !== null && $row['last_archived_time'] >= $row['last_failed_time']));
        return $row;
    }

    public static function keyFromLocation(string $location): string
    {
        return self::bucketAndKey($location)[1];
    }

    /** @return array{0:string,1:string} */
    public static function bucketAndKey(string $location): array
    {
        if (preg_match('#^s3://([^/]+)/(.+)$#', $location, $m) === 1) {
            return [$m[1], $m[2]];
        }
        return ['', ''];
    }

    /** @return array<string,int> exact row counts of the key tables that exist */
    private function countRows(PDO $pdo): array
    {
        $counts = [];
        foreach (self::KEY_TABLES as $table) {
            $exists = $pdo->query('SELECT to_regclass(' . $pdo->quote('public.' . $table) . ') IS NOT NULL')->fetchColumn();
            if ($exists === true || $exists === 't' || $exists === 1) {
                $counts[$table] = (int) $pdo->query('SELECT count(*) FROM public."' . $table . '"')->fetchColumn();
            }
        }
        return $counts;
    }

    private function connect(string $database): PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $this->dbConfig['host'], $this->dbConfig['port'], $database, $this->dbConfig['sslmode'] ?: 'prefer');
        return new PDO($dsn, $this->dbConfig['user'], $this->dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
    }

    /** Writes a 0600 PGPASSFILE for the platform DB user (all databases on the host). */
    private function passFile(): string
    {
        $path = $this->tempFile('pgpass');
        chmod($path, 0600);
        $line = implode(':', [
            self::pgpassEscape((string) $this->dbConfig['host']),
            self::pgpassEscape((string) $this->dbConfig['port']),
            '*',
            self::pgpassEscape((string) $this->dbConfig['user']),
            self::pgpassEscape((string) $this->dbConfig['password']),
        ]) . "\n";
        if (file_put_contents($path, $line) === false) {
            throw new RuntimeException('Cannot write the temporary PGPASSFILE.');
        }
        return $path;
    }

    private static function pgpassEscape(string $v): string
    {
        // .pgpass format: backslash and colon are escaped with a backslash
        return str_replace(':', '\\:', str_replace('\\', '\\\\', $v));
    }

    private function tempFile(string $prefix): string
    {
        $dir = sys_get_temp_dir();
        $path = tempnam($dir, 'yep-' . $prefix . '-');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file in ' . $dir);
        }
        chmod($path, 0600);
        return $path;
    }

    /**
     * Runs a PostgreSQL client tool without a shell. Returns stderr (warnings) on success.
     *
     * @param list<string> $cmd
     */
    private function exec(array $cmd, string $passFile): string
    {
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'PGPASSFILE' => $passFile,
            'PGSSLMODE' => (string) ($this->dbConfig['sslmode'] ?: 'prefer'),
            'PGCONNECT_TIMEOUT' => '10',
            'LC_ALL' => 'C.UTF-8',
        ];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Cannot start ' . $cmd[0]);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $deadline = time() + $this->timeoutSeconds;
        $exit = -1;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = (int) $status['exitcode'];
                break;
            }
            if (time() > $deadline) {
                proc_terminate($proc, 15);
                $stderr .= "\nTimed out after {$this->timeoutSeconds}s";
                break;
            }
            usleep(100_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        if ($exit !== 0) {
            throw new RuntimeException(sprintf('%s failed (exit %d): %s', basename($cmd[0]), $exit, mb_substr(Redactor::redactString(trim($stderr)), 0, 1500)));
        }
        return trim($stderr . "\n" . $stdout);
    }

    private function toolVersion(string $tool): ?string
    {
        $out = @shell_exec(escapeshellcmd($tool) . ' --version 2>/dev/null');
        return is_string($out) ? trim($out) : null;
    }
}
