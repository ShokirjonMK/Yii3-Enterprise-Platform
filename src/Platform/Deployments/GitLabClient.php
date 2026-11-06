<?php

declare(strict_types=1);

namespace App\Platform\Deployments;

use App\Platform\Infrastructure\ExternalServiceException;
use App\Platform\Infrastructure\JsonHttp;
use SensitiveParameter;

/**
 * GitLab REST API v4 adapter for deployments. The platform never connects to servers itself:
 * it only creates a pipeline (POST /projects/:id/pipeline with DEPLOY_ENV / VERSION variables)
 * and polls its status. The pipeline (.gitlab-ci.yml) performs the actual rollout.
 *
 * Token: project access token or deploy-bot PAT with scope `api` (create pipeline) — stored as the
 * encrypted setting ops.gitlab_token.
 */
final class GitLabClient
{
    /** GitLab pipeline status → deployments.status */
    public const STATUS_MAP = [
        'created' => 'running', 'waiting_for_resource' => 'running', 'preparing' => 'running', 'pending' => 'running',
        'running' => 'running', 'scheduled' => 'running', 'manual' => 'running',
        'success' => 'succeeded', 'failed' => 'failed', 'canceled' => 'failed', 'skipped' => 'failed',
    ];

    private ?JsonHttp $http = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $projectId,
        #[SensitiveParameter]
        private readonly ?string $token,
        private readonly mixed $handler = null,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->baseUrl) !== '' && trim($this->projectId) !== '' && $this->token !== null && $this->token !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    /**
     * @param array<string,string> $variables pipeline variables (env_var type)
     * @return array{id:int, status:string, ref:string, sha:string, web_url:string}
     */
    public function triggerPipeline(string $ref, array $variables): array
    {
        $vars = [];
        foreach ($variables as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', (string) $key) !== 1) {
                throw new \InvalidArgumentException("Invalid pipeline variable name \"$key\".");
            }
            $vars[] = ['key' => (string) $key, 'value' => (string) $value, 'variable_type' => 'env_var'];
        }
        $p = $this->http()->request('POST', $this->project() . '/pipeline', ['json' => ['ref' => $ref, 'variables' => $vars]]);
        return self::pipelineRow($p);
    }

    /** @return array{id:int, status:string, ref:string, sha:string, web_url:string} */
    public function pipeline(int $id): array
    {
        return self::pipelineRow($this->http()->request('GET', $this->project() . '/pipelines/' . $id));
    }

    /** @return list<array{id:int, status:string, ref:string, sha:string, web_url:string, created_at:?string, source:?string}> */
    public function recentPipelines(int $limit = 10): array
    {
        $rows = $this->http()->request('GET', $this->project() . '/pipelines', ['query' => ['per_page' => max(1, min(100, $limit)), 'order_by' => 'id', 'sort' => 'desc']]);
        return array_values(array_map(static fn(array $p) => self::pipelineRow($p) + [
            'created_at' => isset($p['created_at']) ? (string) $p['created_at'] : null,
            'source' => isset($p['source']) ? (string) $p['source'] : null,
        ], array_filter($rows, 'is_array')));
    }

    public static function mapStatus(string $gitlabStatus): string
    {
        return self::STATUS_MAP[$gitlabStatus] ?? 'running';
    }

    /** @return array{id:int, status:string, ref:string, sha:string, web_url:string} */
    private static function pipelineRow(array $p): array
    {
        if (!isset($p['id'])) {
            throw new ExternalServiceException('GitLab', 'Unexpected GitLab response (no pipeline id).');
        }
        return [
            'id' => (int) $p['id'],
            'status' => (string) ($p['status'] ?? 'unknown'),
            'ref' => (string) ($p['ref'] ?? ''),
            'sha' => (string) ($p['sha'] ?? ''),
            'web_url' => (string) ($p['web_url'] ?? ''),
        ];
    }

    private function project(): string
    {
        // Numeric id or URL-encoded "group/project" path
        return '/api/v4/projects/' . rawurlencode($this->projectId);
    }

    private function http(): JsonHttp
    {
        if (!$this->isConfigured()) {
            throw new ExternalServiceException('GitLab', 'GitLab is not configured (ops.gitlab_url / ops.gitlab_project_id / ops.gitlab_token).');
        }
        return $this->http ??= new JsonHttp('GitLab', $this->baseUrl, ['PRIVATE-TOKEN' => (string) $this->token], true, 8.0, $this->handler);
    }
}
