<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Deployments\GitLabClient;
use App\Platform\Infrastructure\ExternalServiceException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** GitLab adapter against a mocked API (UNVERIFIED against a real GitLab instance). */
final class OpsGitLabClientTest extends TestCase
{
    private array $history = [];

    private function client(Response ...$responses): GitLabClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new GitLabClient('https://gitlab.example.uz', 'group/platform', 'glpat-secret', $stack);
    }

    private static function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public function testTriggerPipelineWithVariables(): void
    {
        $c = $this->client(self::json(['id' => 4711, 'status' => 'created', 'ref' => 'main', 'sha' => 'abc1234', 'web_url' => 'https://gitlab.example.uz/group/platform/-/pipelines/4711'], 201));
        $p = $c->triggerPipeline('main', ['DEPLOY_ENV' => 'staging', 'VERSION' => 'v1.2.3']);
        self::assertSame(4711, $p['id']);
        $req = $this->history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/api/v4/projects/group%2Fplatform/pipeline', $req->getUri()->getPath());
        self::assertSame('glpat-secret', $req->getHeaderLine('PRIVATE-TOKEN'));
        $body = json_decode((string) $req->getBody(), true);
        self::assertSame('main', $body['ref']);
        self::assertSame([
            ['key' => 'DEPLOY_ENV', 'value' => 'staging', 'variable_type' => 'env_var'],
            ['key' => 'VERSION', 'value' => 'v1.2.3', 'variable_type' => 'env_var'],
        ], $body['variables']);
    }

    public function testPollAndListPipelines(): void
    {
        $c = $this->client(
            self::json(['id' => 1, 'status' => 'success', 'ref' => 'main', 'sha' => 'a', 'web_url' => 'u']),
            self::json([['id' => 3, 'status' => 'running', 'ref' => 'main', 'sha' => 'c', 'web_url' => 'u3', 'created_at' => '2026-11-04T08:00:00Z', 'source' => 'api']]),
        );
        self::assertSame('success', $c->pipeline(1)['status']);
        self::assertSame('succeeded', GitLabClient::mapStatus('success'));
        self::assertSame('failed', GitLabClient::mapStatus('canceled'));
        self::assertSame('running', GitLabClient::mapStatus('pending'));
        $list = $c->recentPipelines(5);
        self::assertSame(3, $list[0]['id']);
        self::assertSame('api', $list[0]['source']);
        parse_str($this->history[1]['request']->getUri()->getQuery(), $q);
        self::assertSame(['per_page' => '5', 'order_by' => 'id', 'sort' => 'desc'], $q);
    }

    public function testErrorsAndConfiguration(): void
    {
        self::assertFalse((new GitLabClient('', '', null))->isConfigured());
        $c = $this->client(self::json(['message' => '401 Unauthorized'], 401));
        try {
            $c->pipeline(5);
            self::fail('expected exception');
        } catch (ExternalServiceException $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertStringNotContainsString('glpat-secret', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->triggerPipeline('main', ['bad-name' => 'x']);
    }
}
