<?php

declare(strict_types=1);

/**
 * Template: web controller (src/Modules/<Module>/Web/<Module>Controller.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$status = $m->hasStatus();
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
    }
}
$statusFilter = $status ? "\n        \$filters['status'] = self::str(\$params, 'status');" : '';
$statusAction = $status ? <<<'PHP'

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $e = $this->service->changeStatus($this->actor(), $this->id(), (string) ($body['status'] ?? ''), isset($body['version']) ? (int) $body['version'] : null);
            $this->responder->success('Status changed to ' . $e->status()->label() . '.');
        } catch (ConflictException | ValidationException $ex) {
            $this->responder->error($ex->getMessage());
        }
        return $this->responder->redirect('web.{{code}}.view', ['id' => $this->id()]);
    }
PHP : '';

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Web;

use App\Modules\{{Module}}\Application\{{Entity}}Service;
use App\Modules\{{Module}}\Domain\{{Entity}};
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * End-user {{label}} pages (web layout): list, detail, create, edit__STATUS_DOC__.
 * Same {{Entity}}Service rules as Admin and API.
 */
final class {{Module}}Controller
{
    public const PER_PAGE = 12;
    public const INPUT = __INPUT__;

    public function __construct(
        private readonly Responder $responder,
        private readonly {{Entity}}Service $service,
        private readonly CurrentUser $currentUser,
        private readonly CurrentRoute $route,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filters = ['q' => self::str($params, 'q')];__STATUS_FILTER__
        $page = $this->service->search($this->actor(), $filters, max(1, (int) ($params['page'] ?? 1)), self::PER_PAGE, self::str($params, 'sort') ?: '-updated_at');
        return $this->responder->render('@modules/{{Module}}/Web/views/index', [
            'page' => $page,
            'filters' => $filters,
            'query' => array_filter($filters, static fn($v) => $v !== ''),
        ], 'web');
    }

    public function view(): ResponseInterface
    {
        $actor = $this->actor();
        $e = $this->service->get($actor, $this->id());
        return $this->responder->render('@modules/{{Module}}/Web/views/view', [
            'entity' => $e,
            'activity' => $this->service->activity($actor, $e->id),__TRANSITIONS__
        ], 'web');
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
        return $this->responder->redirect('web.{{code}}.view', ['id' => $e->id]);
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
            return $this->responder->redirect('web.{{code}}.edit', ['id' => $this->id()]);
        }
        $this->responder->success('{{entityLabel}} saved.');
        return $this->responder->redirect('web.{{code}}.view', ['id' => $e->id]);
    }
__STATUS_ACTION__
    private function form(?{{Entity}} $entity, array $values, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->render('@modules/{{Module}}/Web/views/form', [
            'entity' => $entity,
            'values' => $values,
            'errors' => $errors,
        ], 'web', $status);
    }

    /** @return array<string,mixed> */
    private static function formValues({{Entity}} $e): array
    {
        return [
__FORM_VALUES__
        ];
    }

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
    '__STATUS_DOC__' => $status ? ', lifecycle transitions' : '',
    '__INPUT__' => '[' . implode(', ', array_map($q, $editable)) . ']',
    '__STATUS_FILTER__' => $statusFilter,
    '__TRANSITIONS__' => $status ? "\n            'transitions' => \$e->status()->allowedTransitions()," : '',
    '__DEFAULTS__' => '[' . implode(', ', $defaults) . ']',
    '__STATUS_ACTION__' => $statusAction,
    '__FORM_VALUES__' => implode("\n", $formValues),
    '__UNSET_USERS__' => $requiredUsers === [] ? '' : "\n        foreach (" . '[' . implode(', ', array_map($q, $requiredUsers)) . ']' . " as \$k) {\n            if ((\$in[\$k] ?? null) === '') {\n                unset(\$in[\$k]);\n            }\n        }",
]);
