<?php

declare(strict_types=1);

namespace App\Admin\Jobs;

use App\Platform\Scheduler\Scheduler;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 10 · Jobs & Scheduler — cron schedules (create/edit with live preview, enable/disable, run now, run history).
 * Persistence and audit: Scheduler::save / setEnabled / runNow.
 */
final class SchedulesController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Scheduler $scheduler,
        private readonly JobsReadModel $read,
        private readonly CurrentUser $currentUser,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->responder->render('@admin/Jobs/views/schedules', [
            'schedules' => $this->read->schedules(),
            'runs' => $this->read->runs(null, 40),
        ]);
    }

    public function create(): ResponseInterface
    {
        return $this->form(null, ['code' => '', 'name' => '', 'cron_expression' => '*/15 * * * *', 'timezone' => 'Asia/Tashkent', 'job_type' => '', 'payload' => '{}', 'queue' => 'default', 'enabled' => true, 'overlap' => 'skip']);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->input($request);
        try {
            $id = $this->scheduler->save((string) $this->currentUser->getId(), null, $input, $this->read->types());
        } catch (ValidationException $e) {
            return $this->form(null, $input, $e->errors());
        }
        $this->responder->success('Schedule created.');
        return $this->responder->redirect('admin.jobs.schedules.edit', ['id' => $id]);
    }

    public function edit(CurrentRoute $route): ResponseInterface
    {
        $s = $this->read->schedule((string) $route->getArgument('id'));
        $s['payload'] = json_encode(json_decode((string) $s['payload'], true) ?: new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->form($s, $s);
    }

    public function update(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $s = $this->read->schedule((string) $route->getArgument('id'));
        $input = $this->input($request) + ['code' => $s['code']];
        try {
            $this->scheduler->save((string) $this->currentUser->getId(), (string) $s['id'], $input, $this->read->types());
        } catch (ValidationException $e) {
            return $this->form($s, $input, $e->errors());
        }
        $this->responder->success('Schedule saved.');
        return $this->responder->redirect('admin.jobs.schedules.edit', ['id' => $s['id']]);
    }

    /** HTMX fragment: next run times for the cron expression being edited. */
    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $qp = $request->getQueryParams();
        $cron = trim((string) ($qp['cron_expression'] ?? ''));
        $tz = trim((string) ($qp['timezone'] ?? 'Asia/Tashkent'));
        $runs = [];
        $error = null;
        try {
            if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                throw ValidationException::single('timezone', 'Unknown time zone.');
            }
            $runs = $this->scheduler->preview($cron, $tz, 5);
        } catch (ValidationException $e) {
            $error = $e->getMessage();
        } catch (Throwable) {
            $error = 'Invalid cron expression.';
        }
        return $this->responder->fragment('@admin/Jobs/views/_preview', ['runs' => $runs, 'error' => $error]);
    }

    public function toggle(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $s = $this->read->schedule((string) $route->getArgument('id'));
        $enabled = filter_var(((array) $request->getParsedBody())['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scheduler->setEnabled((string) $this->currentUser->getId(), (string) $s['id'], $enabled);
        $this->responder->success($enabled ? 'Schedule enabled.' : 'Schedule disabled.');
        return $this->responder->redirect('admin.jobs.schedules');
    }

    public function runNow(CurrentRoute $route): ResponseInterface
    {
        $s = $this->read->schedule((string) $route->getArgument('id'));
        $jobId = $this->scheduler->runNow((string) $this->currentUser->getId(), (string) $s['id']);
        $this->responder->success('Schedule triggered; job enqueued.');
        return $this->responder->redirect('admin.jobs.view', ['id' => $jobId]);
    }

    private function form(?array $schedule, array $values, array $errors = []): ResponseInterface
    {
        $preview = [];
        $previewError = null;
        try {
            $preview = $this->scheduler->preview((string) ($values['cron_expression'] ?? ''), (string) ($values['timezone'] ?? 'Asia/Tashkent'), 5);
        } catch (Throwable) {
            $previewError = 'Invalid cron expression.';
        }
        return $this->responder->render('@admin/Jobs/views/schedule-form', [
            'schedule' => $schedule,
            'values' => $values,
            'errors' => $errors,
            'types' => $this->read->types(),
            'queues' => $this->read->queues(),
            'preview' => $preview,
            'previewError' => $previewError,
            'runs' => $schedule === null ? [] : $this->read->runs((string) $schedule['id'], 20),
        ], status: $errors === [] ? 200 : 422);
    }

    private function input(ServerRequestInterface $request): array
    {
        $b = (array) $request->getParsedBody();
        return [
            'code' => trim((string) ($b['code'] ?? '')),
            'name' => trim((string) ($b['name'] ?? '')),
            'cron_expression' => trim((string) ($b['cron_expression'] ?? '')),
            'timezone' => trim((string) ($b['timezone'] ?? 'Asia/Tashkent')),
            'job_type' => (string) ($b['job_type'] ?? ''),
            'payload' => (string) ($b['payload'] ?? '{}') ?: '{}',
            'queue' => (string) ($b['queue'] ?? 'default'),
            'enabled' => isset($b['enabled']) && $b['enabled'] !== '0',
            'overlap' => ($b['overlap'] ?? 'skip') === 'allow' ? 'allow' : 'skip',
        ];
    }
}
