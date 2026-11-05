<?php

declare(strict_types=1);

namespace App\Admin\Workflow;

use App\Platform\Workflow\ApprovalService;
use App\Platform\Workflow\WorkflowDefinition;
use App\Platform\Workflow\WorkflowEngine;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 09 · Workflow & Approvals — definitions (JSON editor + validation report), instances (history, transitions),
 * approvals inbox and decisions (four-eyes).
 */
final class WorkflowController
{
    private const WF_SORT = ['code' => 'code', 'name' => 'name', 'entity_type' => 'entity_type', 'updated_at' => 'updated_at'];
    private const INST_SORT = ['updated_at' => 'i.updated_at', 'created_at' => 'i.created_at', 'state' => 'i.state', 'entity_id' => 'i.entity_id'];
    private const APPROVAL_SORT = ['created_at' => 'r.created_at', 'status' => 'r.status', 'expires_at' => 'r.expires_at'];
    private const APPROVAL_STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'expired'];

    public function __construct(
        private readonly Responder $responder,
        private readonly WorkflowEngine $engine,
        private readonly ApprovalService $approvals,
        private readonly CurrentUser $user,
        private readonly \App\Platform\Access\AccessService $access,
    ) {}

    // ------------------------------------------------------------------------------------------ definitions

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $query = $this->engine->workflowsQuery()->select(['workflows.*',
            new Expression('(SELECT count(*) FROM workflow_instances i WHERE i.workflow_id = workflows.id) AS instances')]);
        if (($q['q'] ?? '') !== '') {
            $query->andWhere(Sql::search((string) $q['q'], ['code', 'name', 'entity_type']));
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 25, (string) ($q['sort'] ?? ''), self::WF_SORT, 'code');
        return $this->responder->render('@admin/Workflow/views/index', [
            'page' => $page, 'query' => array_intersect_key($q, ['q' => 1, 'sort' => 1]),
            'pendingForMe' => count($this->approvals->pendingFor($this->actor())),
        ]);
    }

    public function create(): ResponseInterface
    {
        $example = json_encode([
            'states' => ['draft', 'review', 'published'],
            'initial' => 'draft',
            'transitions' => [
                'submit' => ['from' => ['draft'], 'to' => 'review', 'label' => 'Submit for review'],
                'publish' => ['from' => ['review'], 'to' => 'published', 'approval' => ['permission' => 'approvals.decide', 'required_approvals' => 1]],
                'reject' => ['from' => ['review'], 'to' => 'draft'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $this->renderForm(null, ['definition' => $example, 'enabled' => '1'], []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $in = (array) $request->getParsedBody();
        try {
            $id = $this->engine->createWorkflow($this->actor(), $in);
        } catch (ValidationException $e) {
            return $this->renderForm(null, $in, $e->errors(), 422);
        }
        $this->responder->success('Workflow created.');
        return $this->responder->redirect('admin.workflow.show', ['id' => $id]);
    }

    public function show(CurrentRoute $route): ResponseInterface
    {
        $wf = $this->engine->findWorkflow((string) $route->getArgument('id'));
        $def = $this->engine->definition($wf);
        $counts = $this->engine->instancesQuery()->select(['i.state', 'n' => new Expression('count(*)')])->where(['i.workflow_id' => $wf['id']])->groupBy('i.state')->all();
        return $this->responder->render('@admin/Workflow/views/show', [
            'wf' => $wf, 'def' => $def, 'report' => WorkflowDefinition::analyse(Sql::json($wf['definition'])),
            'counts' => array_column($counts, 'n', 'state'),
        ]);
    }

    public function edit(CurrentRoute $route): ResponseInterface
    {
        $wf = $this->engine->findWorkflow((string) $route->getArgument('id'));
        return $this->renderForm($wf, ['definition' => json_encode(Sql::json($wf['definition']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] + $wf, []);
    }

    public function update(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $wf = $this->engine->findWorkflow((string) $route->getArgument('id'));
        $in = (array) $request->getParsedBody();
        try {
            $this->engine->updateWorkflow($this->actor(), (string) $wf['id'], $in, (int) ($in['version'] ?? $wf['version']));
        } catch (ValidationException $e) {
            return $this->renderForm($wf, $in + $wf, $e->errors(), 422);
        }
        $this->responder->success('Workflow saved.');
        return $this->responder->redirect('admin.workflow.show', ['id' => $wf['id']]);
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        try {
            $this->engine->deleteWorkflow($this->actor(), $id);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.workflow.show', ['id' => $id]);
        }
        $this->responder->success('Workflow deleted.');
        return $this->responder->redirect('admin.workflow');
    }

    /** HTMX: validation report for the JSON in the editor. */
    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        $def = (string) (((array) $request->getParsedBody())['definition'] ?? '');
        return $this->responder->fragment('@admin/Workflow/views/_report', ['report' => WorkflowDefinition::analyse($def)]);
    }

    public function start(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $wf = $this->engine->findWorkflow((string) $route->getArgument('id'));
        $entityId = trim((string) (((array) $request->getParsedBody())['entity_id'] ?? ''));
        try {
            $instance = $this->engine->start((string) $wf['code'], (string) $wf['entity_type'], $entityId, $this->actor(), $this->identityTenant());
        } catch (ValidationException | ConflictException $e) {
            $this->responder->error($e instanceof ValidationException ? implode(' ', array_merge(...array_values($e->errors()))) : $e->getMessage());
            return $this->responder->redirect('admin.workflow.show', ['id' => $wf['id']]);
        }
        $this->responder->success('Workflow instance started.');
        return $this->responder->redirect('admin.workflow.instance', ['id' => $instance['id']]);
    }

    // ------------------------------------------------------------------------------------------ instances

    public function instances(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $query = $this->engine->instancesQuery();
        if (($q['workflow'] ?? '') !== '') {
            $query->andWhere(['w.code' => (string) $q['workflow']]);
        }
        if (($q['state'] ?? '') !== '') {
            $query->andWhere(['i.state' => (string) $q['state']]);
        }
        if (($q['q'] ?? '') !== '') {
            $query->andWhere(Sql::search((string) $q['q'], ['i.entity_id', 'i.entity_type']));
        }
        if (!$this->can('tenants.cross')) {
            $query->andWhere(['or', ['i.tenant_id' => null], ['i.tenant_id' => $this->identityTenant()]]);
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 25, (string) ($q['sort'] ?? ''), self::INST_SORT, '-updated_at');
        return $this->responder->render('@admin/Workflow/views/instances', [
            'page' => $page, 'query' => array_intersect_key($q, ['workflow' => 1, 'state' => 1, 'q' => 1, 'sort' => 1]),
            'workflows' => $this->engine->workflowsQuery()->select('code')->orderBy('code')->column(),
        ]);
    }

    public function instance(CurrentRoute $route): ResponseInterface
    {
        $instance = $this->engine->instance((string) $route->getArgument('id'), $this->actor());
        $wf = $this->engine->findWorkflow((string) $instance['workflow_id']);
        return $this->responder->render('@admin/Workflow/views/instance', [
            'instance' => $instance, 'wf' => $wf, 'def' => $this->engine->definition($wf),
            'history' => $this->engine->history((string) $instance['id']),
            'transitions' => $this->engine->availableTransitions($instance, $this->actor()),
            'approvals' => $this->approvals->query()->where(['r.instance_id' => $instance['id']])->orderBy(['r.created_at' => SORT_DESC])->limit(20)->all(),
        ]);
    }

    public function apply(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $in = (array) $request->getParsedBody();
        try {
            $r = $this->engine->apply($id, (string) ($in['transition'] ?? ''), $this->actor(), isset($in['version']) ? (int) $in['version'] : null, (string) ($in['comment'] ?? ''));
            $r['status'] === 'applied'
                ? $this->responder->success('Transition applied.')
                : $this->responder->info('Approval requested — the transition is applied once it is approved.');
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.workflow.instance', ['id' => $id]);
    }

    // ------------------------------------------------------------------------------------------ approvals

    public function approvals(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $inbox = $this->approvals->pendingFor($this->actor());
        $query = $this->approvals->query();
        if (in_array($q['status'] ?? '', self::APPROVAL_STATUSES, true)) {
            $query->andWhere(['r.status' => $q['status']]);
        }
        if (($q['mine'] ?? '') === '1') {
            $query->andWhere(['r.requested_by' => $this->actor()]);
        }
        if (!$this->can('tenants.cross')) {
            $query->andWhere(['or', ['r.tenant_id' => null], ['r.tenant_id' => $this->identityTenant()]]);
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 25, (string) ($q['sort'] ?? ''), self::APPROVAL_SORT, '-created_at');
        return $this->responder->render('@admin/Workflow/views/approvals', [
            'inbox' => $inbox, 'page' => $page, 'statuses' => self::APPROVAL_STATUSES,
            'query' => array_intersect_key($q, ['status' => 1, 'mine' => 1, 'sort' => 1]),
        ]);
    }

    public function approval(CurrentRoute $route): ResponseInterface
    {
        $r = $this->approvals->find((string) $route->getArgument('id'), $this->actor());
        $decisions = $this->approvals->decisions((string) $r['id']);
        $me = $this->actor();
        $canDecide = $r['status'] === 'pending' && $r['requested_by'] !== $me
            && $this->can('approvals.decide') && $this->can((string) $r['approver_permission'])
            && !in_array($me, array_column($decisions, 'approver_id'), true);
        return $this->responder->render('@admin/Workflow/views/approval', [
            'r' => $r, 'decisions' => $decisions, 'canDecide' => $canDecide, 'isRequester' => $r['requested_by'] === $me,
        ]);
    }

    public function decide(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $in = (array) $request->getParsedBody();
        try {
            $status = $this->approvals->decide($id, $this->actor(), (string) ($in['decision'] ?? ''), (string) ($in['comment'] ?? ''));
            $this->responder->success(match ($status) {
                'approved' => 'Approved — the request is complete.',
                'rejected' => 'Rejected — the request is closed.',
                'cancelled' => 'Approved, but the item had moved on; the request was cancelled.',
                default => 'Your approval was recorded; more approvals are required.',
            });
        } catch (ValidationException $e) {
            $this->responder->error(implode(' ', array_merge(...array_values($e->errors()))));
        } catch (ConflictException | \App\Shared\Domain\Exception\AccessDeniedException $e) {
            // four-eyes violations are audited by the service (access.denied) and shown on the request page
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.workflow.approval', ['id' => $id]);
    }

    public function cancel(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        try {
            $this->approvals->cancel($id, $this->actor());
            $this->responder->success('Request cancelled.');
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.workflow.approval', ['id' => $id]);
    }

    private function renderForm(?array $wf, array $values, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->render('@admin/Workflow/views/form', [
            'wf' => $wf, 'values' => $values, 'errors' => $errors,
            'report' => WorkflowDefinition::analyse((string) ($values['definition'] ?? '')),
        ], status: $status);
    }

    private function can(string $permission): bool
    {
        return $this->access->can($this->actor(), $permission);
    }

    private function identityTenant(): ?string
    {
        $identity = $this->user->getIdentity();
        return $identity instanceof \App\Platform\Identity\Domain\Identity ? $identity->user->tenantId : null;
    }

    private function actor(): string
    {
        return (string) $this->user->getId();
    }
}
