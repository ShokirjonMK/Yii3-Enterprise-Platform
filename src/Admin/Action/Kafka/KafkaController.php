<?php

declare(strict_types=1);

namespace App\Admin\Action\Kafka;

use App\Platform\Audit\AuditLogger;
use App\Platform\Messaging\Kafka\KafkaAdmin;
use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Messaging\Kafka\KafkaConsumerRunner;
use App\Platform\Messaging\OutboxRelay;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use App\Shared\Infrastructure\Logging\Redactor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 11 · Kafka Management — live cluster state (never faked: disabled → "Not configured", unreachable → error),
 * topic registry & drift, provisioning script, consumer groups (lag, pause/resume), outbox, dead letters.
 */
final class KafkaController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly KafkaConfig $config,
        private readonly KafkaAdmin $admin,
        private readonly KafkaConsumerRunner $runner,
        private readonly KafkaReadModel $read,
        private readonly KafkaProvisioningScript $script,
        private readonly OutboxRelay $relay,
        private readonly CurrentUser $currentUser,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): ResponseInterface
    {
        [$state, $meta, $error] = $this->cluster();
        $drift = $this->admin->topicDrift();
        $registered = array_map(fn($r) => $this->config->topic((string) $r['name']), $drift);
        $unregistered = $meta === null ? [] : array_values(array_diff(array_keys($meta['topics']), $registered));
        return $this->responder->render('admin/kafka/index', [
            'state' => $state,
            'meta' => $meta,
            'error' => $error,
            'drift' => $drift,
            'unregistered' => $unregistered,
            'config' => ['brokers' => $this->config->brokers, 'prefix' => $this->config->topicPrefix, 'protocol' => $this->config->securityProtocol, 'client' => $this->config->clientId],
            'outbox' => $this->read->outboxCounts(),
        ]);
    }

    // ------------------------------------------------------------------ topic registry

    public function topicCreate(): ResponseInterface
    {
        return $this->topicForm(null, ['name' => '', 'partitions' => 3, 'replication_factor' => 1, 'retention_hours' => 168, 'cleanup_policy' => 'delete', 'description' => '']);
    }

    public function topicStore(ServerRequestInterface $request): ResponseInterface
    {
        $input = (array) $request->getParsedBody();
        try {
            $name = $this->read->saveTopic(null, $input, $this->currentUser->getId());
        } catch (ValidationException $e) {
            return $this->topicForm(null, $input, $e->errors());
        }
        $this->responder->success(sprintf('Topic "%s" registered. Run the provisioning script to create it on the cluster.', $name));
        return $this->responder->redirect('admin.kafka');
    }

    public function topicEdit(CurrentRoute $route): ResponseInterface
    {
        $t = $this->read->topic((string) $route->getArgument('name'));
        $t['retention_hours'] = (int) $t['retention_ms'] === -1 ? -1 : intdiv((int) $t['retention_ms'], 3_600_000);
        return $this->topicForm($t, $t);
    }

    public function topicUpdate(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $t = $this->read->topic((string) $route->getArgument('name'));
        $input = (array) $request->getParsedBody();
        try {
            $this->read->saveTopic((string) $t['name'], $input, $this->currentUser->getId());
        } catch (ValidationException $e) {
            return $this->topicForm($t, $input + ['name' => $t['name']], $e->errors());
        }
        $this->responder->success('Topic registry updated.');
        return $this->responder->redirect('admin.kafka');
    }

    public function topicDelete(CurrentRoute $route): ResponseInterface
    {
        $name = (string) $route->getArgument('name');
        $this->read->unregisterTopic($name, $this->currentUser->getId());
        $this->responder->success(sprintf('Topic "%s" removed from the registry (the live topic is not deleted).', $name));
        return $this->responder->redirect('admin.kafka');
    }

    public function provision(ServerRequestInterface $request): ResponseInterface
    {
        $rf = $this->rf($request);
        $rows = $this->admin->topicDrift();
        return $this->responder->render('admin/kafka/provision', [
            'script' => $this->script->render($rows, $rf),
            'rows' => $rows,
            'rf' => $rf,
        ]);
    }

    public function provisionDownload(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->download($this->script->render($this->admin->topicDrift(), $this->rf($request)), 'kafka-topics.sh', 'text/x-shellscript; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    // ------------------------------------------------------------------ consumer groups

    public function consumers(): ResponseInterface
    {
        return $this->responder->render('admin/kafka/consumers', [
            'groups' => $this->runner->groups(),
            'states' => $this->read->consumerStates(),
            'processes' => $this->read->consumerProcesses(),
            'enabled' => $this->config->enabled,
        ]);
    }

    /** HTMX fragment: per-partition lag (talks to the broker; loaded lazily). */
    public function lag(CurrentRoute $route): ResponseInterface
    {
        $group = (string) $route->getArgument('group');
        $groups = $this->runner->groups();
        if (!isset($groups[$group])) {
            throw new NotFoundException('Unknown consumer group.');
        }
        $rows = [];
        $error = null;
        if (!$this->config->enabled) {
            $error = 'Not configured';
        } else {
            try {
                $rows = $this->admin->lag($group, KafkaReadModel::groupTopics($group, $groups[$group]));
            } catch (Throwable $e) {
                $error = Redactor::redactString($e->getMessage());
            }
        }
        return $this->responder->fragment('admin/kafka/_lag', ['group' => $group, 'rows' => $rows, 'error' => $error]);
    }

    public function pauseConsumer(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $group = (string) $route->getArgument('group');
        if (!isset($this->runner->groups()[$group])) {
            throw new NotFoundException('Unknown consumer group.');
        }
        $paused = filter_var(((array) $request->getParsedBody())['paused'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->read->setConsumerPaused($group, $paused, $this->currentUser->getId());
        $this->responder->success($paused ? sprintf('Consumer group "%s" paused (takes effect within 5 s).', $group) : sprintf('Consumer group "%s" resumed.', $group));
        return $this->responder->redirect('admin.kafka.consumers');
    }

    // ------------------------------------------------------------------ outbox

    public function outbox(ServerRequestInterface $request): ResponseInterface
    {
        $qp = $request->getQueryParams();
        $status = (string) ($qp['status'] ?? '');
        $status = in_array($status, ['pending', 'failed', 'published'], true) ? $status : '';
        $q = mb_substr(trim((string) ($qp['q'] ?? '')), 0, 100);
        return $this->responder->render('admin/kafka/outbox', [
            'counts' => $this->read->outboxCounts(),
            'page' => $this->read->outbox($status, $q, (int) ($qp['page'] ?? 1), (string) ($qp['sort'] ?? '')),
            'filters' => ['status' => $status, 'q' => $q],
            'sort' => (string) ($qp['sort'] ?? ''),
        ]);
    }

    public function outboxRequeueAll(): ResponseInterface
    {
        $n = $this->relay->requeueFailed();
        $this->audit->record('outbox.requeued_all', 'outbox', null, metadata: ['count' => $n], category: 'operations', severity: 'notice', actorId: $this->currentUser->getId());
        $this->responder->success(sprintf('%d failed event(s) re-queued for publishing.', $n));
        return $this->responder->redirect('admin.kafka.outbox');
    }

    public function outboxRequeue(CurrentRoute $route): ResponseInterface
    {
        try {
            $this->read->requeueOutboxRow((string) $route->getArgument('id'), $this->currentUser->getId());
            $this->responder->success('Event re-queued.');
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.kafka.outbox', [], ['status' => 'failed']);
    }

    // ------------------------------------------------------------------ dead letters

    public function deadLetters(ServerRequestInterface $request): ResponseInterface
    {
        $qp = $request->getQueryParams();
        $state = in_array($qp['state'] ?? 'open', ['open', 'replayed', 'all'], true) ? (string) ($qp['state'] ?? 'open') : 'open';
        $group = mb_substr(trim((string) ($qp['group'] ?? '')), 0, 128);
        $q = mb_substr(trim((string) ($qp['q'] ?? '')), 0, 100);
        return $this->responder->render('admin/kafka/dead-letters', [
            'page' => $this->read->deadLetters($state, $group, $q, (int) ($qp['page'] ?? 1), (string) ($qp['sort'] ?? '')),
            'filters' => ['state' => $state, 'group' => $group, 'q' => $q],
            'sort' => (string) ($qp['sort'] ?? ''),
            'groups' => $this->read->deadLetterGroups(),
        ]);
    }

    public function deadLetter(CurrentRoute $route): ResponseInterface
    {
        return $this->responder->render('admin/kafka/dead-letter', [
            'dl' => $this->read->deadLetter($this->intArg($route, 'id')),
            'enabled' => $this->config->enabled,
        ]);
    }

    public function replay(CurrentRoute $route): ResponseInterface
    {
        $id = $this->intArg($route, 'id');
        $dl = $this->read->deadLetter($id);
        $actor = $this->currentUser->getId();
        if (!$this->config->enabled) {
            $this->responder->error('Kafka is not configured; the message cannot be replayed.');
            return $this->responder->redirect('admin.kafka.dlq.view', ['id' => $id]);
        }
        try {
            $this->runner->replay($id, $actor);
            $this->audit->record('kafka.dlq.replayed', 'kafka_dead_letter', $id, metadata: ['topic' => $dl['topic'], 'group' => $dl['consumer_group'], 'previously_replayed' => $dl['replayed_at'] !== null], category: 'operations', severity: 'notice', actorId: $actor);
            $this->responder->success(sprintf('Message re-published to %s.', $dl['topic']));
        } catch (Throwable $e) {
            $this->audit->record('kafka.dlq.replayed', 'kafka_dead_letter', $id, metadata: ['error' => Redactor::redactString($e->getMessage())], result: 'failure', category: 'operations', severity: 'warning', actorId: $actor);
            $this->responder->error('Replay failed: ' . Redactor::redactString($e->getMessage()));
        }
        return $this->responder->redirect('admin.kafka.dlq.view', ['id' => $id]);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0:string, 1:?array, 2:?string} state (up|not_configured|down), metadata, error */
    private function cluster(): array
    {
        if (!$this->config->enabled) {
            return ['not_configured', null, null];
        }
        if (!extension_loaded('rdkafka')) {
            return ['down', null, 'ext-rdkafka is not installed.'];
        }
        try {
            return ['up', $this->admin->metadata(), null];
        } catch (Throwable $e) {
            return ['down', null, Redactor::redactString($e->getMessage())];
        }
    }

    private function topicForm(?array $topic, array $values, array $errors = []): ResponseInterface
    {
        return $this->responder->render('admin/kafka/topic-form', ['topic' => $topic, 'values' => $values, 'errors' => $errors], status: $errors === [] ? 200 : 422);
    }

    private function rf(ServerRequestInterface $request): ?int
    {
        $rf = filter_var($request->getQueryParams()['rf'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
        return $rf === false ? null : $rf;
    }

    private function intArg(CurrentRoute $route, string $name): int
    {
        $v = (string) $route->getArgument($name);
        if (!ctype_digit($v)) {
            throw new NotFoundException('Not found.');
        }
        return (int) $v;
    }
}
