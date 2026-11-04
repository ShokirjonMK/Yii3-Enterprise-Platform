<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use App\Platform\Settings\ConfigRevision;
use App\Platform\Settings\SettingsService;
use Throwable;

/**
 * Builds {@see KafkaConfig} from the settings `kafka.*` (admin-editable, env overrides, secrets decrypted).
 * `KAFKA_ENABLED` stays an infrastructure switch (environment); the runtime switch is the feature flag
 * `messaging.kafka`. When the settings table is not available yet (fresh install, migrations) the
 * environment-only bootstrap configuration is used.
 *
 * Author: ShokirjonMK
 */
final class KafkaConfigFactory
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ConfigRevision $revision,
        private readonly array $params,
    ) {}

    public function create(): KafkaConfig
    {
        try {
            $s = fn(string $k) => $this->settings->get('kafka.' . $k);
            $str = static fn(mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;
            $onFailure = (string) $s('on_failure');
            return new KafkaConfig(
                (bool) $this->params['enabled'],
                (string) $s('brokers'),
                (string) $s('client_id'),
                (string) $s('topic_prefix'),
                (string) $s('security_protocol'),
                $str($s('sasl_mechanism')),
                $str($s('sasl_username')),
                $str($s('sasl_password')),
                $str($s('ssl_ca_location')),
                (string) $s('producer_acks'),
                (string) $s('producer_compression'),
                (int) $s('producer_linger_ms'),
                (int) $s('producer_flush_timeout_ms'),
                max(1, (int) $s('producer_flush_retries')),
                (string) $s('consumer_auto_offset_reset'),
                (int) $s('consumer_poll_timeout_ms'),
                (int) $s('consumer_session_timeout_ms'),
                (int) $s('consumer_max_poll_interval_ms'),
                max(1, (int) $s('retry_attempts')),
                max(0, (int) $s('retry_backoff_ms')),
                max(1, (int) $s('max_deliveries')),
                in_array($onFailure, KafkaConfig::ON_FAILURE, true) ? $onFailure : 'retry_dlq',
            );
        } catch (Throwable) {
            return KafkaConfig::fromParams($this->params);
        }
    }

    /** Re-reads the settings bypassing the per-request revision memo (long-running processes). */
    public function fresh(): KafkaConfig
    {
        $this->revision->reset();
        return $this->create();
    }
}
