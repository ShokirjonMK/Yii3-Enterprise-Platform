<?php

declare(strict_types=1);

namespace App\Platform\Incidents;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\User;
use App\Platform\Notifications\Notifier;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * 24 · Incident management.
 *
 * Lifecycle: open → investigating → identified → monitoring → resolved → closed
 * (forward skips are allowed, resolved/monitoring/identified can be re-opened to investigating;
 * closed is final). Every change writes an incident_events timeline entry and an audit record.
 *
 * Automatic incidents (health probe, alerts) are keyed by a fingerprint: at most one non-resolved
 * incident per fingerprint exists (partial unique index incidents_open_fingerprint_uq).
 *
 * Notifications: in-app + e-mail to on-call users (holders of incidents.manage) with template
 * `incident.opened`, plus a Telegram chat alert when configured.
 */
final class IncidentService
{
    public const SEVERITIES = ['sev1', 'sev2', 'sev3', 'sev4'];
    public const STATUSES = ['open', 'investigating', 'identified', 'monitoring', 'resolved', 'closed'];
    public const TRANSITIONS = [
        'open' => ['investigating', 'identified', 'monitoring', 'resolved'],
        'investigating' => ['identified', 'monitoring', 'resolved'],
        'identified' => ['investigating', 'monitoring', 'resolved'],
        'monitoring' => ['investigating', 'identified', 'resolved'],
        'resolved' => ['investigating', 'closed'],
        'closed' => [],
    ];
    public const SORTABLE = ['opened_at' => 'i.opened_at', 'number' => 'i.number', 'severity' => 'i.severity', 'status' => 'i.status', 'service' => 'i.service'];
    public const ONCALL_PERMISSION = 'incidents.manage';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly TelegramAlerter $telegram,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl = '',
    ) {}

    public function list(array $filters, int $page, ?string $sort, int $perPage = 25): Page
    {
        $q = $this->baseQuery();
        $status = (string) ($filters['status'] ?? 'active');
        if ($status === 'active') {
            $q->andWhere(['not in', 'i.status', ['resolved', 'closed']]);
        } elseif (in_array($status, self::STATUSES, true)) {
            $q->andWhere(['i.status' => $status]);
        }
        if (in_array($filters['severity'] ?? '', self::SEVERITIES, true)) {
            $q->andWhere(['i.severity' => $filters['severity']]);
        }
        if (($filters['q'] ?? '') !== '') {
            $q->andWhere(\App\Platform\Infrastructure\OpsSql::contains((string) $filters['q'], ['i.title', 'i.service', 'i.number', 'i.fingerprint']));
        }
        return Paginator::paginate($q, $page, $perPage, $sort, self::SORTABLE, '-opened_at');
    }

    public function get(string $id): array
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            throw new NotFoundException('Incident not found.');
        }
        return $this->baseQuery()->where(['i.id' => $id])->one() ?? throw new NotFoundException('Incident not found.');
    }

    /** @return list<array<string,mixed>> */
    public function timeline(string $id): array
    {
        return (new Query($this->db))->select(['e.*', 'actor' => 'u.username'])->from(['e' => 'incident_events'])
            ->leftJoin(['u' => 'users'], 'u.id = e.actor_id')
            ->where(['e.incident_id' => $id])->orderBy(['e.created_at' => SORT_ASC, 'e.id' => SORT_ASC])->all();
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT count(*) FILTER (WHERE status NOT IN ('resolved','closed')) AS active,
                   count(*) FILTER (WHERE status NOT IN ('resolved','closed') AND severity IN ('sev1','sev2')) AS major,
                   count(*) FILTER (WHERE status NOT IN ('resolved','closed') AND acknowledged_at IS NULL) AS unacknowledged,
                   count(*) FILTER (WHERE resolved_at > now() - interval '30 days') AS resolved_30d,
                   COALESCE(round(avg(EXTRACT(EPOCH FROM acknowledged_at - opened_at)) FILTER (WHERE opened_at > now() - interval '30 days' AND acknowledged_at IS NOT NULL) / 60), 0) AS mtta_min,
                   COALESCE(round(avg(EXTRACT(EPOCH FROM resolved_at - opened_at)) FILTER (WHERE resolved_at > now() - interval '30 days') / 60), 0) AS mttr_min
            FROM incidents
            SQL)->queryOne();
        return array_map('intval', $row ?: []);
    }

    /**
     * @param array{title?:string, severity?:string, service?:string, description?:string, commander_id?:string} $input
     */
    public function open(?User $actor, array $input, string $source = 'manual', ?string $fingerprint = null): string
    {
        if ($actor !== null) {
            $this->access->assert($actor->id, 'incidents.manage');
        }
        $title = trim((string) ($input['title'] ?? ''));
        $severity = (string) ($input['severity'] ?? 'sev3');
        $service = trim((string) ($input['service'] ?? 'platform')) ?: 'platform';
        $errors = [];
        if ($title === '' || mb_strlen($title) > 255) {
            $errors['title'][] = 'Title is required (max 255 characters).';
        }
        if (!in_array($severity, self::SEVERITIES, true)) {
            $errors['severity'][] = 'Choose a severity.';
        }
        if (preg_match('/^[A-Za-z0-9._:\/-]{1,96}$/', $service) !== 1) {
            $errors['service'][] = 'Service: letters, digits and . _ : / - (max 96).';
        }
        $commander = $this->validUserId($input['commander_id'] ?? null);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $row = [
            'title' => $title,
            'severity' => $severity,
            'service' => $service,
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 20000),
            'commander_id' => $commander,
            'opened_by' => $actor?->id,
            'source' => $source,
            'fingerprint' => $fingerprint,
        ];
        $incident = $this->db->transaction(function () use ($row, $actor): array {
            $inc = $this->db->createCommand(
                'INSERT INTO incidents (title, severity, service, description, commander_id, opened_by, source, fingerprint)
                 VALUES (:title, :severity, :service, :description, :commander_id, :opened_by, :source, :fingerprint) RETURNING id, number',
                array_combine(array_map(static fn($k) => ':' . $k, array_keys($row)), array_values($row)),
            )->queryOne();
            $this->event((string) $inc['id'], 'opened', sprintf('Opened (%s, %s) via %s.', $row['severity'], $row['service'], $row['source']), $actor?->id);
            $this->audit->record('incident.opened', 'incident', (string) $inc['id'], null, $row, ['number' => (int) $inc['number']],
                category: 'ops', severity: in_array($row['severity'], ['sev1', 'sev2'], true) ? 'warning' : 'notice',
                actorId: $actor?->id, actorName: $actor?->username);
            return $inc;
        });
        $this->announce((string) $incident['id'], (int) $incident['number'], $row);
        return (string) $incident['id'];
    }

    /**
     * Opens an automatic incident for a fingerprint, or appends to the one already open.
     *
     * @return array{id:string, created:bool}
     */
    public function autoOpen(string $fingerprint, string $title, string $severity, string $service, string $description): array
    {
        $existing = $this->openByFingerprint($fingerprint);
        if ($existing !== null) {
            return ['id' => $existing, 'created' => false];
        }
        try {
            $id = $this->open(null, ['title' => $title, 'severity' => $severity, 'service' => $service, 'description' => $description], 'auto', $fingerprint);
            return ['id' => $id, 'created' => true];
        } catch (\Yiisoft\Db\Exception\IntegrityException) {
            // Concurrent probe opened it first (partial unique index) — use that one
            return ['id' => (string) $this->openByFingerprint($fingerprint), 'created' => false];
        }
    }

    /** Resolves the open automatic incident of a fingerprint. Returns its id or null. */
    public function autoResolve(string $fingerprint, string $message): ?string
    {
        $id = $this->openByFingerprint($fingerprint);
        if ($id === null) {
            return null;
        }
        $this->changeStatus(null, $id, 'resolved', $message);
        return $id;
    }

    public function openByFingerprint(string $fingerprint): ?string
    {
        $id = (new Query($this->db))->select('id')->from('incidents')
            ->where(['fingerprint' => $fingerprint])->andWhere(['not in', 'status', ['resolved', 'closed']])->scalar();
        return $id === false || $id === null ? null : (string) $id;
    }

    public function acknowledge(User $actor, string $id): void
    {
        $this->access->assert($actor->id, 'incidents.manage');
        $this->db->transaction(function () use ($actor, $id): void {
            $inc = $this->lock($id);
            if ($inc['acknowledged_at'] !== null) {
                throw new ConflictException('The incident is already acknowledged.');
            }
            $update = ['acknowledged_at' => new Expression('now()'), 'updated_at' => new Expression('now()')];
            if ($inc['commander_id'] === null) {
                $update['commander_id'] = $actor->id;
            }
            if ($inc['status'] === 'open') {
                $update['status'] = 'investigating';
            }
            $this->db->createCommand()->update('incidents', $update, ['id' => $id])->execute();
            $this->event($id, 'acknowledged', 'Acknowledged by ' . $actor->username . (isset($update['commander_id']) ? ' (incident commander)' : '') . '.', $actor->id);
            $this->audit->record('incident.acknowledged', 'incident', $id, ['status' => $inc['status']], ['status' => $update['status'] ?? $inc['status']], [],
                category: 'ops', severity: 'notice', actorId: $actor->id, actorName: $actor->username);
        });
    }

    public function transition(User $actor, string $id, string $to, string $note = ''): void
    {
        $this->access->assert($actor->id, 'incidents.manage');
        $this->changeStatus($actor, $id, $to, $note);
    }

    public function addNote(User $actor, string $id, string $body): void
    {
        $this->access->assert($actor->id, 'incidents.manage');
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 10000) {
            throw ValidationException::single('body', 'The note must contain 1–10000 characters.');
        }
        $this->db->transaction(function () use ($actor, $id, $body): void {
            $this->lock($id);
            $this->event($id, 'note', $body, $actor->id);
            $this->db->createCommand()->update('incidents', ['updated_at' => new Expression('now()')], ['id' => $id])->execute();
            $this->audit->record('incident.note_added', 'incident', $id, null, null, ['length' => mb_strlen($body)],
                category: 'ops', actorId: $actor->id, actorName: $actor->username);
        });
    }

    public function assignCommander(User $actor, string $id, ?string $userId): void
    {
        $this->access->assert($actor->id, 'incidents.manage');
        $commander = $this->validUserId($userId);
        if ($userId !== null && $userId !== '' && $commander === null) {
            throw ValidationException::single('commander_id', 'Unknown user.');
        }
        $this->db->transaction(function () use ($actor, $id, $commander): void {
            $inc = $this->lock($id);
            $this->db->createCommand()->update('incidents', ['commander_id' => $commander, 'updated_at' => new Expression('now()')], ['id' => $id])->execute();
            $name = $commander === null ? 'nobody' : (string) (new Query($this->db))->select('username')->from('users')->where(['id' => $commander])->scalar();
            $this->event($id, 'commander', 'Incident commander: ' . $name . '.', $actor->id);
            $this->audit->record('incident.commander_assigned', 'incident', $id, ['commander_id' => $inc['commander_id']], ['commander_id' => $commander], [],
                category: 'ops', actorId: $actor->id, actorName: $actor->username);
        });
    }

    public function savePostmortem(User $actor, string $id, string $text): void
    {
        $this->access->assert($actor->id, 'incidents.manage');
        if (mb_strlen($text) > 100000) {
            throw ValidationException::single('postmortem', 'The postmortem is too long (max 100000 characters).');
        }
        $this->db->transaction(function () use ($actor, $id, $text): void {
            $inc = $this->lock($id);
            $this->db->createCommand()->update('incidents', ['postmortem' => trim($text) === '' ? null : $text, 'updated_at' => new Expression('now()')], ['id' => $id])->execute();
            $this->event($id, 'postmortem', $inc['postmortem'] === null ? 'Postmortem added.' : 'Postmortem updated.', $actor->id);
            $this->audit->record('incident.postmortem_saved', 'incident', $id, null, null, ['length' => mb_strlen($text)],
                category: 'ops', actorId: $actor->id, actorName: $actor->username);
        });
    }

    /** Active users holding the on-call permission (incidents.manage) through any role. */
    public function onCallUserIds(int $limit = 50): array
    {
        return array_map('strval', $this->db->createCommand(<<<'SQL'
            WITH RECURSIVE holders(name) AS (
                SELECT CAST(:perm AS varchar)
                UNION
                SELECT c.parent FROM yii_rbac_item_child c JOIN holders h ON c.child = h.name
            )
            SELECT DISTINCT u.id FROM users u
            JOIN yii_rbac_assignment a ON a.user_id = u.id::text
            JOIN holders h ON h.name = a.item_name
            WHERE u.status = 'active' AND u.deleted_at IS NULL
            ORDER BY u.id
            LIMIT :lim
            SQL, [':perm' => self::ONCALL_PERMISSION, ':lim' => $limit])->queryColumn());
    }

    private function changeStatus(?User $actor, string $id, string $to, string $note): void
    {
        if (!in_array($to, self::STATUSES, true)) {
            throw ValidationException::single('status', 'Unknown status.');
        }
        $inc = $this->db->transaction(function () use ($actor, $id, $to, $note): array {
            $inc = $this->lock($id);
            $from = (string) $inc['status'];
            if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new ConflictException("Transition $from → $to is not allowed.");
            }
            if ($to === 'closed' && in_array($inc['severity'], ['sev1', 'sev2'], true) && trim((string) $inc['postmortem']) === '') {
                throw ValidationException::single('postmortem', 'A postmortem is required before closing a sev1/sev2 incident.');
            }
            $update = ['status' => $to, 'updated_at' => new Expression('now()')];
            if ($to === 'resolved') {
                $update['resolved_at'] = new Expression('now()');
            } elseif ($to === 'closed') {
                $update['closed_at'] = new Expression('now()');
            } elseif ($from === 'resolved') {
                $update['resolved_at'] = null; // re-opened
            }
            if ($inc['acknowledged_at'] === null && $actor !== null && $to !== 'open') {
                $update['acknowledged_at'] = new Expression('now()');
            }
            $this->db->createCommand()->update('incidents', $update, ['id' => $id])->execute();
            $this->event($id, 'status', "Status: $from → $to." . (trim($note) !== '' ? ' ' . trim($note) : ''), $actor?->id);
            $this->audit->record('incident.status_changed', 'incident', $id, ['status' => $from], ['status' => $to], ['note' => mb_substr($note, 0, 500)],
                category: 'ops', severity: 'notice', actorId: $actor?->id, actorName: $actor?->username);
            return $inc;
        });
        if ($to === 'resolved') {
            $this->telegram->send(sprintf("✅ <b>Resolved</b> incident #%d [%s] %s\n%s",
                (int) $inc['number'], TelegramAlerter::escape((string) $inc['severity']), TelegramAlerter::escape((string) $inc['title']), TelegramAlerter::escape(mb_substr($note, 0, 500))));
        }
    }

    /** Notifications to on-call users + Telegram chat. Never throws. */
    private function announce(string $id, int $number, array $row): void
    {
        $params = ['number' => $number, 'severity' => $row['severity'], 'title' => $row['title'], 'service' => $row['service'], 'url' => $this->baseUrl . '/admin/incidents/' . $id];
        $channels = in_array($row['severity'], ['sev1', 'sev2'], true) ? ['inapp', 'email'] : ['inapp'];
        $notified = 0;
        try {
            foreach ($this->onCallUserIds() as $userId) {
                $notified += count($this->notifier->notify($userId, 'incident.opened', $params, $channels, 'incidents'));
            }
        } catch (Throwable $e) {
            $this->logger->error('Incident notifications failed', ['incident' => $id, 'exception' => $e]);
        }
        $sent = $this->telegram->send(sprintf("🚨 <b>[%s] Incident #%d</b>: %s\nService: %s · Source: %s\n%s",
            TelegramAlerter::escape(strtoupper($row['severity'])), $number, TelegramAlerter::escape($row['title']),
            TelegramAlerter::escape($row['service']), TelegramAlerter::escape($row['source']), TelegramAlerter::escape($params['url'])));
        try {
            $this->event($id, 'notified', sprintf('Notified %d on-call deliveries%s.', $notified, $sent ? ' + Telegram alert chat' : ''), null);
        } catch (Throwable) {
        }
    }

    private function event(string $incidentId, string $type, string $body, ?string $actorId): void
    {
        $this->db->createCommand()->insert('incident_events', ['incident_id' => $incidentId, 'type' => $type, 'body' => $body, 'actor_id' => $actorId])->execute();
    }

    private function lock(string $id): array
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            throw new NotFoundException('Incident not found.');
        }
        $row = $this->db->createCommand('SELECT * FROM incidents WHERE id = :id FOR UPDATE', [':id' => $id])->queryOne();
        if ($row === null || $row === false) {
            throw new NotFoundException('Incident not found.');
        }
        return $row;
    }

    private function validUserId(mixed $userId): ?string
    {
        if (!is_string($userId) || preg_match('/^[0-9a-f-]{36}$/i', $userId) !== 1) {
            return null;
        }
        $ok = (new Query($this->db))->from('users')->where(['id' => $userId, 'deleted_at' => null])->exists();
        return $ok ? $userId : null;
    }

    private function baseQuery(): Query
    {
        return (new Query($this->db))
            ->select(['i.*', 'commander' => 'c.username', 'opener' => 'o.username'])
            ->from(['i' => 'incidents'])
            ->leftJoin(['c' => 'users'], 'c.id = i.commander_id')
            ->leftJoin(['o' => 'users'], 'o.id = i.opened_by');
    }
}
