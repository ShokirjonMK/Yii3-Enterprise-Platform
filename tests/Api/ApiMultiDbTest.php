<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Settings\ConfigRevision;
use App\Tests\Support\TestContainer;

/**
 * /api/v1/multidb over HTTP: the same contract for MariaDB, PostgreSQL, MongoDB and ClickHouse datasets —
 * CRUD, PATCH semantics, filters / sort / paging, statistics, validation shape, scopes and permissions.
 */
final class ApiMultiDbTest extends ApiTestCase
{
    private static bool $ready = false;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$ready) {
            TestContainer::service(ConfigRevision::class)->bump();
            TestContainer::service(\App\Modules\MultiDb\Application\DatasetService::class)->install(false);
            self::$ready = true;
        }
        $this->token = $this->token(\App\Tests\Support\Fixtures::user(['business_admin']))['access_token'];
    }

    public function testDatasetCatalogue(): void
    {
        $r = $this->api('GET', '/api/v1/multidb', $this->token);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        $data = $this->json($r)['data'];
        self::assertSame(['products', 'kpis', 'orders', 'articles', 'pageviews'], array_column($data, 'code'));
        self::assertSame(['SQL', 'SQL', 'SQL', 'MongoDB', 'ClickHouse'], array_column($data, 'engine'));
        foreach ($data as $d) {
            self::assertTrue($d['available'], $d['code'] . ' reachable');
            self::assertNotEmpty($d['fields']);
        }
    }

    /** @return iterable<string, array{string, array<string,mixed>, array<string,mixed>}> */
    public static function rows(): iterable
    {
        $m = bin2hex(random_bytes(4));
        yield 'MariaDB' => ['products', ['sku' => 'API-' . $m, 'name' => 'Planshet ' . $m, 'category' => 'electronics', 'price' => 3200000, 'stock' => 7, 'active' => true], ['stock' => 2]];
        yield 'PostgreSQL #2' => ['kpis', ['metric' => 'nps', 'period' => sprintf('18%02d-%02d-01', random_int(0, 99), random_int(1, 12)), 'value' => '55.5', 'unit' => 'count', 'note' => 'api ' . $m], ['value' => '60.00']];
        yield 'PostgreSQL #3' => ['orders', ['order_no' => 'API-' . $m, 'customer' => 'Api ' . $m, 'total' => '10.00', 'currency' => 'EUR', 'status' => 'delivered', 'closed_on' => '2026-10-01'], ['status' => 'cancelled']];
        yield 'MongoDB' => ['articles', ['title' => 'Api ' . $m, 'body' => 'Body', 'tags' => ['Api', 'test'], 'published' => false], ['published' => true]];
        yield 'ClickHouse' => ['pageviews', ['viewed_at' => '2026-11-05 08:00:00', 'path' => '/api-' . $m, 'country' => 'KZ', 'device' => 'tablet', 'duration_ms' => 999], ['country' => 'TR']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rows')]
    public function testCrud(string $ds, array $input, array $patch): void
    {
        $r = $this->api('POST', '/api/v1/multidb/' . $ds, $this->token, $input);
        self::assertSame(201, $r->getStatusCode(), $this->http->body());
        $row = $this->json($r)['data'];
        self::assertSame('/api/v1/multidb/' . $ds . '/' . $row['id'], $r->getHeaderLine('Location'));

        $r = $this->api('GET', '/api/v1/multidb/' . $ds . '/' . $row['id'], $this->token);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame($row, $this->json($r)['data']);

        $r = $this->api('PATCH', '/api/v1/multidb/' . $ds . '/' . $row['id'], $this->token, $patch);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        $updated = $this->json($r)['data'];
        foreach ($patch as $k => $v) {
            self::assertEquals($v, $updated[$k]);
        }
        $title = array_key_first($input);
        self::assertSame($row[$title], $updated[$title], 'PATCH leaves other fields alone');

        $r = $this->api('GET', '/api/v1/multidb/' . $ds . '/stats', $this->token);
        self::assertSame(200, $r->getStatusCode());
        self::assertNotEmpty($this->json($r)['data']);

        self::assertSame(204, $this->api('DELETE', '/api/v1/multidb/' . $ds . '/' . $row['id'], $this->token)->getStatusCode());
        $this->assertError($this->api('GET', '/api/v1/multidb/' . $ds . '/' . $row['id'], $this->token), 404, 'not_found');
    }

    public function testListFiltersSortAndPaging(): void
    {
        $r = $this->api('GET', '/api/v1/multidb/products?filter[category]=sport&sort=-price&per_page=2', $this->token);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        $body = $this->json($r);
        self::assertSame(['sport'], array_values(array_unique(array_column($body['data'], 'category'))));
        $prices = array_map('floatval', array_column($body['data'], 'price'));
        $sorted = $prices;
        rsort($sorted);
        self::assertSame($sorted, $prices);
        self::assertSame(2, $body['meta']['pagination']['per_page']);
        $this->assertError($this->api('GET', '/api/v1/multidb/products?sort=-nope', $this->token), 422, 'validation_failed');
        $this->assertError($this->api('GET', '/api/v1/multidb/pageviews?filter[device]=fax', $this->token), 422, 'validation_failed');
        $this->assertError($this->api('GET', '/api/v1/multidb/unknown', $this->token), 404, 'not_found');
        $this->assertError($this->api('GET', '/api/v1/multidb/articles/not-an-object-id', $this->token), 404, 'not_found');
    }

    public function testValidationShape(): void
    {
        $e = $this->assertError($this->api('POST', '/api/v1/multidb/orders', $this->token, ['order_no' => str_repeat('x', 40), 'total' => '-1', 'currency' => 'GBP', 'closed_on' => '2026-02-30', 'extra' => 1]), 422, 'validation_failed');
        foreach (['order_no', 'customer', 'total', 'currency', 'status', 'closed_on', '_'] as $f) {
            self::assertIsList($e['details'][$f] ?? null, $f);
        }
    }

    public function testScopesAndPermissions(): void
    {
        $readOnly = $this->token(\App\Tests\Support\Fixtures::user(['business_admin']), 'multidb.read')['access_token'];
        self::assertSame(200, $this->api('GET', '/api/v1/multidb/kpis', $readOnly)->getStatusCode());
        $this->assertError($this->api('POST', '/api/v1/multidb/kpis', $readOnly, ['metric' => 'nps']), 403, 'insufficient_scope');

        $user = $this->token(\App\Tests\Support\Fixtures::user(['user']))['access_token'];
        self::assertSame(200, $this->api('GET', '/api/v1/multidb/articles', $user)->getStatusCode(), 'role user may read');
        self::assertSame(403, $this->api('DELETE', '/api/v1/multidb/products/1', $user)->getStatusCode(), 'but not write');
        self::assertSame(401, $this->api('GET', '/api/v1/multidb', null)->getStatusCode());
    }
}
