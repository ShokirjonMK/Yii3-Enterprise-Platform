<?php

declare(strict_types=1);

namespace App\Admin\Action\Audit;

use App\Platform\Audit\AuditLogger;
use App\Platform\Audit\AuditVerifier;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Http\Responder;
use App\Shared\Infrastructure\Security\Encrypter;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 20 · Audit & Compliance: search, detail with before/after diff, redacted CSV export,
 * HMAC integrity verification, partitions & retention.
 */
final class AuditController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly AuditSearch $search,
        private readonly AuditVerifier $verifier,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly Encrypter $encrypter,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $filters = $this->search->normalize($query);
        $page = Paginator::paginate($this->search->query($this->actor(), $filters), (int) ($query['page'] ?? 1), 50, (string) ($query['sort'] ?? ''), AuditSearch::SORTABLE, '-occurred_at');
        return $this->responder->render('admin/audit/index', [
            'page' => $page,
            'filters' => $filters,
            'sort' => $page->sort,
        ]);
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $row = ctype_digit($id) ? $this->search->find($this->actor(), (int) $id) : null;
        if ($row === null) {
            throw new NotFoundException('Audit record not found.');
        }
        $valid = hash_equals($this->encrypter->hmac(AuditLogger::canonical($row), 'audit'), (string) $row['row_hmac']);
        $before = Sql::json($row['before'], null);
        $after = Sql::json($row['after'], null);
        return $this->responder->render('admin/audit/view', [
            'row' => $row,
            'before' => $before,
            'after' => $after,
            'metadata' => Sql::json($row['metadata'], []),
            'diff' => AuditSearch::diff($before, $after),
            'hmacValid' => $valid,
        ]);
    }

    /** Streams a redacted CSV (spooled through php://temp, bounded memory; max 50 000 rows). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor();
        $filters = $this->search->normalize($request->getQueryParams());
        $resource = fopen('php://temp/maxmemory:' . (4 * 1024 * 1024), 'w+b');
        fwrite($resource, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet tools
        fputcsv($resource, AuditSearch::CSV_HEADER, ',', '"', '');
        $rows = 0;
        $query = $this->search->query($actor, $filters)->orderBy(['a.occurred_at' => SORT_DESC, 'a.id' => SORT_DESC])->limit(AuditSearch::EXPORT_LIMIT);
        foreach ($query->each(1000) as $r) {
            fputcsv($resource, AuditSearch::csvRow($r), ',', '"', '');
            $rows++;
        }
        $this->audit->record('audit.exported', 'audit_log', null, null, null, ['filters' => array_filter($filters), 'rows' => $rows, 'truncated' => $rows >= AuditSearch::EXPORT_LIMIT],
            category: 'security', severity: 'notice', actorId: $actor->id, actorName: $actor->username);
        rewind($resource);
        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="audit-' . gmdate('Ymd-His') . '.csv"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Export-Rows', (string) $rows)
            ->withBody($this->streams->createStreamFromResource($resource));
    }

    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor();
        $body = (array) $request->getParsedBody();
        $filters = $this->search->normalize($body);
        $from = $filters['from'] !== '' ? $filters['from'] : null;
        $to = $filters['to'] !== '' ? (new \DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d') : null;
        $t = microtime(true);
        $result = $this->verifier->verify($from, $to, 200000);
        $result['duration_ms'] = (int) ((microtime(true) - $t) * 1000);
        $result['from'] = $from;
        $result['to'] = $filters['to'] ?: null;
        $this->audit->record('audit.verified', 'audit_log', null, null, null,
            ['from' => $from, 'to' => $to, 'checked' => $result['checked'], 'invalid' => count($result['invalid']), 'invalid_ids' => array_slice($result['invalid'], 0, 50)],
            result: $result['invalid'] === [] ? 'success' : 'failure', category: 'security', severity: $result['invalid'] === [] ? 'info' : 'critical',
            actorId: $actor->id, actorName: $actor->username);
        return $this->responder->render('admin/audit/partitions', $this->partitionParams() + ['verification' => $result]);
    }

    public function partitions(): ResponseInterface
    {
        return $this->responder->render('admin/audit/partitions', $this->partitionParams() + ['verification' => null]);
    }

    private function partitionParams(): array
    {
        return [
            'partitions' => $this->search->partitions(),
            'retentionMonths' => (int) $this->settings->get('retention.audit_months'),
        ];
    }

    private function actor(): User
    {
        $identity = $this->currentUser->getIdentity();
        if (!$identity instanceof Identity) {
            throw new AccessDeniedException();
        }
        return $identity->user;
    }
}
