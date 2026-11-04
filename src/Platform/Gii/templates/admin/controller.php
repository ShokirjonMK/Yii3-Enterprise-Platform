<?php

declare(strict_types=1);

/**
 * Template: admin controller (src/Admin/Action/<Module>/<Module>Controller.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$status = $m->hasStatus();
$report = $m->has('report');
$filterKeys = array_map(static fn($f) => $f->name, $m->filterable());
$editable = array_map(static fn($f) => $f->name, $m->editable());
$requiredUsers = array_map(static fn($f) => $f->name, array_filter($m->ofType('user'), static fn($f) => $f->required));
$formValues = [];
foreach ($m->editable() as $f) {
    $formValues[] = '            ' . $q($f->name) . ' => ' . FieldCode::formValue($f, '$e') . ',';
}
$formValues[] = "            'version' => \$e->version(),";
$defaults = [];
foreach ($m->editable() as $f) {
    if ($f->type === 'enum') {
        $defaults[] = $q($f->name) . ' => ' . $q($f->defaultOption());
    } elseif ($f->type === 'bool') {
        $defaults[] = $q($f->name) . ' => ' . $q($f->default === 'true' ? '1' : '0');
    }
}

$uses = ['use App\Modules\{{Module}}\Application\{{Entity}}Service;', 'use App\Modules\{{Module}}\Domain\{{Entity}};'];
if ($status) {
    $uses[] = 'use App\Modules\{{Module}}\Domain\{{Entity}}Status;';
}
array_push($uses, 'use App\Platform\Identity\Domain\Identity;', 'use App\Platform\Identity\Domain\User;');
if ($report) {
    $uses[] = 'use App\Platform\Storage\ObjectStorage;';
}
array_push(
    $uses,
    'use App\Shared\Domain\Exception\ConflictException;',
    'use App\Shared\Domain\Exception\NotFoundException;',
    'use App\Shared\Domain\Exception\ValidationException;',
    'use App\Shared\Infrastructure\Http\Responder;',
    'use Psr\Http\Message\ResponseInterface;',
    'use Psr\Http\Message\ServerRequestInterface;',
    'use Yiisoft\Router\CurrentRoute;',
    'use Yiisoft\User\CurrentUser;',
);

$statusAction = $status ? <<<'PHP'

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $e = $this->service->changeStatus($this->actor(), $this->id(), (string) ($body['status'] ?? ''),
                isset($body['version']) ? (int) $body['version'] : null, trim((string) ($body['comment'] ?? '')));
            $this->responder->success('Status changed to ' . $e->status()->label() . '.');
        } catch (ConflictException | ValidationException $ex) {
            $this->responder->error($ex->getMessage());
        }
        return $this->responder->redirect('admin.{{code}}.view', ['id' => $this->id()]);
    }
PHP : '';

$reportActions = $report ? <<<'PHP'

    public function requestReport(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $filters = [];
        foreach (['q', ...self::FILTERS] as $k) {
            if (self::str($body, $k) !== '') {
                $filters[$k] = self::str($body, $k);
            }
        }
        $r = $this->service->requestReport($this->actor(), $filters);
        $this->responder->success('Report generation queued.');
        return $this->responder->redirect('admin.{{code}}.report', ['id' => $r['report_id']]);
    }

    public function report(): ResponseInterface
    {
        return $this->responder->render('admin/{{slug}}/report', [
            'report' => $this->service->report($this->actor(), $this->id()),
            'storageConfigured' => $this->storage->isConfigured(),
        ]);
    }

    public function download(): ResponseInterface
    {
        $report = $this->service->report($this->actor(), $this->id());
        if ($report['status'] !== 'done' || $report['path'] === null) {
            throw new NotFoundException('The report is not ready.');
        }
        $csv = $this->storage->filesystem()->read((string) $report['path']);
        return $this->responder->download($csv, '{{slug}}-report-' . substr((string) $report['id'], 0, 8) . '.csv', 'text/csv; charset=utf-8');
    }
PHP : '';

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Admin\Action\{{Module}};

__USES__

/**
 * Admin · {{label}}: list with search/filters/sort/paging (HTMX), create/edit with validation errors (422),
 * detail with activity timeline__STATUS_DOC__, delete (confirm)__REPORT_DOC__. All rules live in {{Entity}}Service.
 */
final class {{Module}}Controller
{
    public const PER_PAGE = 20;
    public const FILTERS = __FILTERS__;
    public const INPUT = __INPUT__;

    public function __construct(
        private readonly Responder $responder,
        private readonly {{Entity}}Service $service,
        private readonly CurrentUser $currentUser,
        private readonly CurrentRoute $route,__STORAGE__
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor();
        $params = $request->getQueryParams();
        $filters = ['q' => self::str($params, 'q')];
        foreach (self::FILTERS as $k) {
            $filters[$k] = self::str($params, $k);
        }
        $page = $this->service->search($actor, $filters, max(1, (int) ($params['page'] ?? 1)), self::PER_PAGE, self::str($params, 'sort') ?: null);
        return $this->responder->render('admin/{{slug}}/index', [
            'page' => $page,
            'filters' => $filters,
            'query' => array_filter($filters + ['sort' => self::str($params, 'sort')], static fn($v) => $v !== ''),__COUNTS____REPORTS__
        ]);
    }

    public function createForm(): ResponseInterface
    {
        return $this->form(null, __DEFAULTS__, []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->input($request);
        try {
            $e = $this->service->create($this->actor(), $input);
        } catch (ValidationException $ex) {
            return $this->form(null, $input, $ex->errors(), 422);
        }
        $this->responder->success('{{entityLabel}} created.');
        return $this->responder->redirect('admin.{{code}}.view', ['id' => $e->id]);
    }

    public function view(): ResponseInterface
    {
        $actor = $this->actor();
        $e = $this->service->get($actor, $this->id());
        return $this->responder->render('admin/{{slug}}/view', [
            'entity' => $e,
            'activity' => $this->service->activity($actor, $e->id),__TRANSITIONS__
        ]);
    }

    public function editForm(): ResponseInterface
    {
        $e = $this->service->get($this->actor(), $this->id());
        return $this->form($e, self::formValues($e), []);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor();
        $input = $this->input($request);
        $version = (int) (((array) $request->getParsedBody())['version'] ?? 0);
        try {
            $e = $this->service->update($actor, $this->id(), $input, $version);
        } catch (ValidationException $ex) {
            return $this->form($this->service->get($actor, $this->id()), $input + ['version' => $version], $ex->errors(), 422);
        } catch (ConflictException $ex) {
            $this->responder->error($ex->getMessage());
            return $this->responder->redirect('admin.{{code}}.edit', ['id' => $this->id()]);
        }
        $this->responder->success('{{entityLabel}} saved.');
        return $this->responder->redirect('admin.{{code}}.view', ['id' => $e->id]);
    }
__STATUS_ACTION__
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $this->service->delete($this->actor(), $this->id(), isset($body['version']) ? (int) $body['version'] : null);
        } catch (ConflictException $ex) {
            $this->responder->error($ex->getMessage());
            return $this->responder->redirect('admin.{{code}}.view', ['id' => $this->id()]);
        }
        $this->responder->success('{{entityLabel}} deleted.');
        return $this->responder->redirect('admin.{{code}}');
    }
__REPORT_ACTIONS__
    // ------------------------------------------------------------------ helpers

    private function form(?{{Entity}} $entity, array $values, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->render('admin/{{slug}}/form', [
            'entity' => $entity,
            'values' => $values,
            'errors' => $errors,
        ], status: $status);
    }

    /** @return array<string,mixed> form values of an existing record */
    public static function formValues({{Entity}} $e): array
    {
        return [
__FORM_VALUES__
        ];
    }

    /** @return array<string,string> editable attributes from the POST body (strings only) */
    private function input(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $in = [];
        foreach (self::INPUT as $k) {
            if (array_key_exists($k, $body) && is_string($body[$k])) {
                $in[$k] = $body[$k];
            }
        }__UNSET_USERS__
        return $in;
    }

    private function actor(): User
    {
        $identity = $this->currentUser->getIdentity();
        if (!$identity instanceof Identity) {
            throw new NotFoundException();
        }
        return $identity->user;
    }

    private function id(): string
    {
        return (string) $this->route->getArgument('id');
    }

    private static function str(array $a, string $k): string
    {
        return isset($a[$k]) && is_scalar($a[$k]) ? trim((string) $a[$k]) : '';
    }
}

PHP, [
    '__USES__' => implode("\n", $uses),
    '__STATUS_DOC__' => $status ? ', lifecycle transitions' : '',
    '__REPORT_DOC__' => $report ? ' and the background CSV report' : '',
    '__FILTERS__' => '[' . implode(', ', array_map($q, $filterKeys)) . ']',
    '__INPUT__' => '[' . implode(', ', array_map($q, $editable)) . ']',
    '__STORAGE__' => $report ? "\n        private readonly ObjectStorage \$storage," : '',
    '__COUNTS__' => $status ? "\n            'counts' => \$this->service->countsByStatus(\$actor)," : '',
    '__REPORTS__' => $report ? "\n            'reports' => \$this->service->recentReports(\$actor)," : '',
    '__DEFAULTS__' => '[' . implode(', ', $defaults) . ']',
    '__TRANSITIONS__' => $status ? "\n            'transitions' => \$e->status()->allowedTransitions()," : '',
    '__STATUS_ACTION__' => $statusAction,
    '__REPORT_ACTIONS__' => $reportActions,
    '__FORM_VALUES__' => implode("\n", $formValues),
    '__UNSET_USERS__' => $requiredUsers === [] ? '' : "\n        foreach (" . '[' . implode(', ', array_map($q, $requiredUsers)) . ']' . " as \$k) {\n            if ((\$in[\$k] ?? null) === '') {\n                unset(\$in[\$k]); // empty = keep / default to the acting user\n            }\n        }",
]);
