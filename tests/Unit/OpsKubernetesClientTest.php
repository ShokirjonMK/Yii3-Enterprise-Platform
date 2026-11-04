<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Infrastructure\ExternalServiceException;
use App\Platform\Infrastructure\KubernetesClient;
use App\Shared\Domain\Exception\ValidationException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OpsKubernetesClientTest extends TestCase
{
    /** @var list<array{request:\Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function client(Response ...$responses): KubernetesClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new KubernetesClient('https://k8s.example:6443', 'sa-token-xyz', 'platform', true, $stack);
    }

    private static function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public function testNotConfigured(): void
    {
        $c = new KubernetesClient('', null);
        self::assertFalse($c->isConfigured());
        $this->expectException(ExternalServiceException::class);
        $c->deployments();
    }

    public function testListsDeploymentsWithBearerToken(): void
    {
        $c = $this->client(self::json(['items' => [[
            'metadata' => ['name' => 'app', 'creationTimestamp' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200)],
            'spec' => ['replicas' => 3, 'template' => ['spec' => ['containers' => [['image' => 'registry/app:1.2.3']]]]],
            'status' => ['readyReplicas' => 2, 'availableReplicas' => 2, 'updatedReplicas' => 3,
                'conditions' => [['type' => 'Available', 'status' => 'False', 'reason' => 'MinimumReplicasUnavailable']]],
        ]]]));
        $d = $c->deployments();
        self::assertSame('app', $d[0]['name']);
        self::assertSame(3, $d[0]['replicas']);
        self::assertSame(2, $d[0]['ready']);
        self::assertSame(['registry/app:1.2.3'], $d[0]['images']);
        self::assertSame('2h', $d[0]['age']);
        self::assertSame(['Available: MinimumReplicasUnavailable'], $d[0]['conditions']);
        $req = $this->history[0]['request'];
        self::assertSame('Bearer sa-token-xyz', $req->getHeaderLine('Authorization'));
        self::assertSame('/apis/apps/v1/namespaces/platform/deployments', $req->getUri()->getPath());
    }

    public function testPodsNodesAndHpas(): void
    {
        $c = $this->client(
            self::json(['items' => [['metadata' => ['name' => 'app-1'], 'spec' => ['nodeName' => 'n1', 'containers' => [[], []]],
                'status' => ['phase' => 'Running', 'podIP' => '10.0.0.5', 'containerStatuses' => [
                    ['ready' => true, 'restartCount' => 1], ['ready' => false, 'restartCount' => 4, 'state' => ['waiting' => ['reason' => 'CrashLoopBackOff']]],
                ]]]]]),
            self::json(['items' => [['metadata' => ['name' => 'n1', 'labels' => ['node-role.kubernetes.io/control-plane' => '']],
                'spec' => [], 'status' => ['conditions' => [['type' => 'Ready', 'status' => 'True']], 'nodeInfo' => ['kubeletVersion' => 'v1.31.2'],
                    'allocatable' => ['cpu' => '4', 'memory' => '16Gi'], 'addresses' => [['type' => 'InternalIP', 'address' => '192.168.1.10']]]]]]),
            self::json(['items' => [['metadata' => ['name' => 'app'], 'spec' => ['minReplicas' => 2, 'maxReplicas' => 10, 'scaleTargetRef' => ['kind' => 'Deployment', 'name' => 'app'],
                'metrics' => [['resource' => ['target' => ['averageUtilization' => 70]]]]],
                'status' => ['currentReplicas' => 3, 'desiredReplicas' => 4, 'currentMetrics' => [['type' => 'Resource', 'resource' => ['name' => 'cpu', 'current' => ['averageUtilization' => 85]]]]]]]]),
        );
        $p = $c->pods()[0];
        self::assertSame('CrashLoopBackOff', $p['phase']);
        self::assertSame('1/2', $p['ready']);
        self::assertSame(5, $p['restarts']);
        $n = $c->nodes()[0];
        self::assertTrue($n['ready']);
        self::assertSame(['control-plane'], $n['roles']);
        self::assertSame('192.168.1.10', $n['ip']);
        $h = $c->hpas()[0];
        self::assertSame('Deployment/app', $h['target']);
        self::assertSame(['cpu: 85% / 70%'], $h['metrics']);
        self::assertSame('/apis/autoscaling/v2/namespaces/platform/horizontalpodautoscalers', $this->history[2]['request']->getUri()->getPath());
    }

    public function testScaleSendsMergePatch(): void
    {
        $c = $this->client(self::json(['metadata' => ['name' => 'worker'], 'spec' => ['replicas' => 5]]));
        $r = $c->scale('worker', 5);
        self::assertSame(['name' => 'worker', 'replicas' => 5], $r);
        $req = $this->history[0]['request'];
        self::assertSame('PATCH', $req->getMethod());
        self::assertSame('/apis/apps/v1/namespaces/platform/deployments/worker/scale', $req->getUri()->getPath());
        self::assertSame('application/merge-patch+json', $req->getHeaderLine('Content-Type'));
        self::assertSame('{"spec":{"replicas":5}}', (string) $req->getBody());
    }

    public function testScaleValidation(): void
    {
        $c = $this->client();
        try {
            $c->scale('Bad_Name', 1);
            self::fail('invalid name accepted');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $c->scale('app', 1000);
    }

    public function testApiErrorsAreReportedWithoutToken(): void
    {
        $c = $this->client(self::json(['kind' => 'Status', 'message' => 'deployments.apps is forbidden: User "system:serviceaccount:platform:ops" cannot list'], 403));
        try {
            $c->deployments();
            self::fail('expected exception');
        } catch (ExternalServiceException $e) {
            self::assertSame(403, $e->httpStatus);
            self::assertStringContainsString('forbidden', $e->getMessage());
            self::assertStringNotContainsString('sa-token-xyz', $e->getMessage());
        }
    }
}
