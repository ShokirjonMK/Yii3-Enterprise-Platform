# Workflow & Approvals (Admin section 09)

## Definition format (`workflows.definition`, JSON)

```json
{
  "states": ["draft", "active", "on_hold", "completed", "archived"],
  "initial": "draft",
  "transitions": {
    "activate": {"label": "Activate", "from": ["draft"], "to": "active", "permission": "projects.update"},
    "archive":  {"from": ["completed"], "to": "archived",
                 "approval": {"permission": "approvals.decide", "required_approvals": 1, "expires_hours": 168}}
  }
}
```

* `states`: names `^[a-z][a-z0-9_]{0,63}$` (or objects `{"code", "label"}`); `from` may be `"*"`.
* `permission` (optional): checked with `AccessService` when the transition is listed/applied.
* `approval` (optional): the transition creates an `approval_requests` row instead of changing the state.

Validation (`WorkflowDefinition::analyse()`, also live in the admin editor): JSON syntax, duplicate/invalid states,
unknown initial/source/target states, invalid permission names, approval settings (1–10 approvals, 1–8760 h),
**unreachable states** (BFS from `initial`) are errors; no transitions / no terminal state / self-loops are warnings.
Updating a definition cannot remove states that existing instances are in.

## Engine API (`App\Platform\Workflow\WorkflowEngine`)

```php
$i = $engine->start('project.lifecycle', 'project', $projectId, $actorId, $tenantId);   // state = initial, history "start"
$engine->availableTransitions($i, $actorId);   // [{name, label, to, to_label, requires_approval, pending_approval_id}]
$r = $engine->apply($i['id'], 'activate', $actorId, $i['version'], 'comment');
// $r['status'] = 'applied' | 'pending_approval' ($r['approval_id'])
$engine->instanceFor('project.lifecycle', 'project', $projectId);
$engine->history($i['id']);
```

Guarantees: one instance per (workflow, entity); transition must be allowed from the current state (`invalid_transition`);
permission check; optimistic locking on `workflow_instances.version` (`stale_version` → `ConflictException`); every change
writes `workflow_transitions` and an audit record (`workflow.transition`, entity type/id of the business object) in the
same transaction; pending approvals of the previous state are cancelled when the state changes; tenant isolation
(`tenants.cross` bypass, foreign instances → 404).

## Approvals (`ApprovalService`) — four-eyes

* `decide($requestId, $approverId, 'approve'|'reject', $comment)`: requires `approvals.decide` **and** the request's
  `approver_permission`; the **requester can never decide**; each approver once (`UNIQUE (request_id, approver_id)`);
  rejection requires a comment and closes the request; `required_approvals` approvals → `approved` and the pending
  transition is applied **atomically** (row lock `FOR UPDATE`); if the instance moved on meanwhile → `cancelled`.
* Expiry: `expires_at` passed → cannot be decided (`expired`); job `platform.approvals.expire` (schedule every 5 min,
  seeded by `M261104040002WorkflowSeed`) marks overdue requests.
* `request(...)` creates generic (non-workflow) requests; `cancel()` lets the requester withdraw; `pendingFor($userId)`
  is the inbox.

## Seeded definition for the Projects module

`project.lifecycle` (entity type `project`): `draft → active ⇄ on_hold → completed → archived`, `reopen` completed → active,
**archive requires 1 approval** (`approvals.decide`, 168 h). Transitions carry no permission — the Projects module
authorises access to the project; add e.g. `"permission": "projects.update"` in Admin → Workflow if desired.

## Admin UI (`/admin/workflow`)

Workflows list/detail (states with instance counts, transitions, validation report), JSON editor with live validation
(`workflow.manage`), start an instance (operators/testing), instances list (filter workflow/state/entity) with history
and the *available transitions for me*, approvals: **inbox (waiting for my decision)** + all requests (status, mine),
request detail with decisions and approve/reject with comment (`approvals.decide`).

## Status

| Item | Status | Evidence |
|---|---|---|
| Definition validation | TESTED | `tests/Unit/IntegrationPrimitivesTest::testWorkflowDefinitionValidation` |
| Engine + approvals (four-eyes, quorum, reject, expiry, optimistic lock, permissions) | TESTED | `tests/Integration/WorkflowEngineTest` |
| Admin UI end-to-end incl. second approver | TESTED | `tests/E2E/Admin/WorkflowAdminTest` |
| Approver notifications (Notifier) | NOT IMPLEMENTED | approvers see the inbox; e-mail/Telegram fan-out per permission needs a "users with permission" query in AccessService |

— Author: ShokirjonMK
