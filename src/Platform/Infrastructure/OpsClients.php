<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure;

use App\Environment;
use App\Platform\Deployments\GitLabClient;
use App\Platform\Monitoring\AlertmanagerClient;
use App\Platform\Monitoring\PrometheusClient;
use App\Platform\Settings\SettingsService;

/**
 * Builds the external operations clients from the current settings / environment, so that a
 * settings change (System Settings → Operations) takes effect without a restart.
 *
 *   Kubernetes  ops.kubernetes_api, ops.kubernetes_token, ops.kubernetes_namespace (+ K8S_CA_FILE)
 *   GitLab      ops.gitlab_url, ops.gitlab_project_id, ops.gitlab_token (+ GITLAB_DEPLOY_REF)
 *   Prometheus  PROMETHEUS_URL; Alertmanager ALERTMANAGER_URL (default http://alertmanager:9093)
 *   Links       GRAFANA_URL, JAEGER_URL
 */
final class OpsClients
{
    public const IN_CLUSTER_CA = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';

    /** @param callable|null $httpHandler Guzzle handler shared by all clients (tests inject a MockHandler) */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly mixed $httpHandler = null,
    ) {}

    public function kubernetes(): KubernetesClient
    {
        $ca = Environment::nullableString('K8S_CA_FILE') ?? (is_file(self::IN_CLUSTER_CA) ? self::IN_CLUSTER_CA : null);
        return new KubernetesClient(
            (string) $this->settings->get('ops.kubernetes_api'),
            self::str($this->settings->get('ops.kubernetes_token')),
            (string) ($this->settings->get('ops.kubernetes_namespace') ?: 'platform'),
            // TLS is always verified: against the cluster CA when known, otherwise the system trust store
            $ca ?? true,
            $this->httpHandler,
        );
    }

    public function gitlab(): GitLabClient
    {
        return new GitLabClient(
            (string) $this->settings->get('ops.gitlab_url'),
            (string) $this->settings->get('ops.gitlab_project_id'),
            self::str($this->settings->get('ops.gitlab_token')),
            $this->httpHandler,
        );
    }

    public function gitlabRef(): string
    {
        return Environment::string('GITLAB_DEPLOY_REF', 'main');
    }

    public function prometheus(): PrometheusClient
    {
        return new PrometheusClient(self::str($this->settings->get('observability.prometheus_url')), $this->httpHandler, 2.0);
    }

    public function alertmanager(): AlertmanagerClient
    {
        return new AlertmanagerClient(self::str($this->settings->get('observability.alertmanager_url')), $this->httpHandler);
    }

    /** @return array{grafana:?string, jaeger:?string, prometheus:?string, alertmanager:?string} */
    public function links(): array
    {
        return [
            'grafana' => self::str($this->settings->get('observability.grafana_url')),
            'jaeger' => self::str($this->settings->get('observability.jaeger_url')),
            'prometheus' => self::str($this->settings->get('observability.prometheus_public_url')),
            'alertmanager' => self::str($this->settings->get('observability.alertmanager_public_url')),
        ];
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
