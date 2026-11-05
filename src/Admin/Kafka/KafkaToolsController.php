<?php

declare(strict_types=1);

namespace App\Admin\Kafka;

use App\Platform\Audit\AuditLogger;
use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Messaging\Kafka\KafkaConsumerRunner;
use App\Platform\Messaging\Kafka\KafkaHandlerDiscovery;
use App\Platform\Messaging\Kafka\KafkaPublisher;
use App\Platform\Settings\SettingsForm;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use App\Shared\Infrastructure\Logging\Redactor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\User\CurrentUser;

/**
 * 11 · Kafka Management → Handlers (consumer groups + #[KafkaChannel] discovery), Publish (JSON message /
 * batch, broker-confirmed, audited) and Settings (connection, security, producer, consumer, failure policy).
 * Workflow adopted from muxtorov98/yii2-kafka (worker/start, kafka-publish/send|batch, config/kafka.php),
 * managed from the admin panel instead of files.
 *
 * Author: ShokirjonMK
 */
final class KafkaToolsController
{
    public const SETTINGS = [
        'Connection' => ['kafka.brokers', 'kafka.client_id', 'kafka.topic_prefix'],
        'Security' => ['kafka.security_protocol', 'kafka.sasl_mechanism', 'kafka.sasl_username', 'kafka.sasl_password', 'kafka.ssl_ca_location'],
        'Producer' => ['kafka.producer_acks', 'kafka.producer_compression', 'kafka.producer_linger_ms', 'kafka.producer_flush_timeout_ms', 'kafka.producer_flush_retries'],
        'Consumer' => ['kafka.consumer_auto_offset_reset', 'kafka.consumer_poll_timeout_ms', 'kafka.consumer_session_timeout_ms', 'kafka.consumer_max_poll_interval_ms'],
        'Failure handling' => ['kafka.retry_attempts', 'kafka.retry_backoff_ms', 'kafka.max_deliveries', 'kafka.on_failure'],
    ];

    public function __construct(
        private readonly Responder $responder,
        private readonly KafkaConfig $config,
        private readonly KafkaConsumerRunner $runner,
        private readonly KafkaHandlerDiscovery $discovery,
        private readonly KafkaPublisher $publisher,
        private readonly SettingsForm $form,
        private readonly ConnectionInterface $db,
        private readonly CurrentUser $currentUser,
        private readonly AuditLogger $audit,
    ) {}

    public function handlers(): ResponseInterface
    {
        $state = [];
        foreach ((new Query($this->db))->from('consumer_state')->all() as $r) {
            $state[(string) $r['consumer_group']] = (bool) $r['paused'];
        }
        $workers = (new Query($this->db))->select(['queues', 'cnt' => 'count(*)'])->from('workers')
            ->where(['type' => 'kafka'])->andWhere("heartbeat_at > now() - interval '1 minute'")->groupBy('queues')->all();
        return $this->responder->render('@admin/Kafka/views/handlers', [
            'groups' => $this->runner->groups(),
            'discovered' => $this->discovery->handlers(),
            'paused' => $state,
            'alive' => array_column($workers, 'cnt', 'queues'),
            'config' => $this->config,
        ]);
    }

    public function publishForm(): ResponseInterface
    {
        return $this->renderPublish(['topic' => '', 'key' => '', 'payload' => "{\n  \"user\": \"admin\",\n  \"subject\": \"Hello\",\n  \"message\": \"Test message from the admin panel\"\n}", 'batch' => false], [], null);
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        $b = (array) $request->getParsedBody();
        $v = ['topic' => trim((string) ($b['topic'] ?? '')), 'key' => trim((string) ($b['key'] ?? '')), 'payload' => (string) ($b['payload'] ?? ''), 'batch' => ($b['batch'] ?? '') === '1'];
        $actor = (string) $this->currentUser->getId();
        try {
            $ids = $v['batch']
                ? $this->publisher->batch($v['topic'], KafkaPublisher::decodeList($v['payload']), $v['key'] ?: null)
                : [$this->publisher->send($v['topic'], $v['payload'], $v['key'] ?: null)];
        } catch (\InvalidArgumentException $e) {
            return $this->renderPublish($v, ['payload' => [$e->getMessage()]], null, 422);
        } catch (Throwable $e) {
            $this->audit->record('kafka.message.published', 'kafka_topic', $v['topic'], metadata: ['error' => Redactor::redactString($e->getMessage())], result: 'failure', category: 'operations', severity: 'warning', actorId: $actor);
            return $this->renderPublish($v, ['topic' => [Redactor::redactString($e->getMessage())]], null, 502);
        }
        $this->audit->record('kafka.message.published', 'kafka_topic', $v['topic'], metadata: ['count' => count($ids), 'event_ids' => array_slice($ids, 0, 20), 'key' => $v['key'] ?: null], category: 'operations', severity: 'notice', actorId: $actor);
        return $this->renderPublish($v, [], $ids);
    }

    public function settings(): ResponseInterface
    {
        return $this->renderSettings([]);
    }

    public function saveSettings(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $changed = $this->form->save((string) $this->currentUser->getId(), array_merge(...array_values(self::SETTINGS)), (array) $request->getParsedBody(), 'kafka.manage', 'Admin → Kafka → Settings');
        } catch (ValidationException $e) {
            return $this->renderSettings($e->errors(), 422);
        }
        $this->responder->success($changed === []
            ? 'Nothing changed.'
            : sprintf('Saved %d setting(s). Consumers and the outbox relay reconnect automatically within a few seconds.', count($changed)));
        return $this->responder->redirect('admin.kafka.settings');
    }

    private function renderSettings(array $errors, int $status = 200): ResponseInterface
    {
        $sections = [];
        foreach (self::SETTINGS as $title => $keys) {
            $sections[$title] = $this->form->fields($keys);
        }
        return $this->responder->render('@admin/Kafka/views/settings', ['sections' => $sections, 'errors' => $errors, 'enabled' => $this->config->enabled], status: $status);
    }

    private function renderPublish(array $values, array $errors, ?array $ids, int $status = 200): ResponseInterface
    {
        return $this->responder->render('@admin/Kafka/views/publish', [
            'values' => $values,
            'errors' => $errors,
            'ids' => $ids,
            'topics' => (new Query($this->db))->select('name')->from('kafka_topics')->orderBy('name')->column(),
            'prefix' => $this->config->topicPrefix,
            'enabled' => $this->config->enabled,
        ], status: $status);
    }
}
