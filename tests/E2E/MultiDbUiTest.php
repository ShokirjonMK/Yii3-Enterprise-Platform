<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Modules\MultiDb\Application\DatasetService;
use App\Platform\Settings\ConfigRevision;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use App\Tests\Support\WebClient;
use PHPUnit\Framework\TestCase;

/**
 * Multi-DB demo through nginx: Admin overview (all five engines), CRUD forms with 422 validation on MariaDB and
 * MongoDB datasets, HTMX filters on ClickHouse; Web cards and permission checks for a regular user.
 */
final class MultiDbUiTest extends TestCase
{
    private static bool $ready = false;

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
        if (!self::$ready) {
            TestContainer::service(ConfigRevision::class)->bump();
            TestContainer::service(DatasetService::class)->install();
            self::$ready = true;
        }
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    public function testAdminOverviewShowsEveryEngine(): void
    {
        [$web] = AdminSession::login(['business_admin']);
        self::assertSame(200, $web->get('/admin/multidb')->getStatusCode(), substr($web->body(), 0, 500));
        foreach (['products', 'kpis', 'orders', 'articles', 'pageviews'] as $ds) {
            self::assertStringContainsString('data-dataset="' . $ds . '"', $web->body());
        }
        foreach (['MongoDB', 'ClickHouse', 'Install tables and sample data', '/admin/multidb/pageviews'] as $s) {
            self::assertStringContainsString($s, $web->body());
        }
        self::assertStringNotContainsString('Unavailable', $web->body());

        // HTMX filter on the ClickHouse dataset returns only <main>
        $r = $web->htmx('GET', '/admin/multidb/pageviews?device=tablet&sort=-duration_ms');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('multidb-table', $web->body());
        self::assertStringNotContainsString('<span class="tag">mobile</span>', $web->body());

        $web->get('/admin/multidb');
        $r = $web->post('/admin/multidb/install');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Demo tables are ready', $web->body());
    }

    public function testAdminCrudOnMariaDb(): void
    {
        [$web] = AdminSession::login(['business_admin']);
        $web->get('/admin/multidb/products/create');
        self::assertSame(422, $web->post('/admin/multidb/products/create', ['sku' => 'EL-1001', 'price' => 'abc'])->getStatusCode());
        self::assertStringContainsString('field__error', $web->body());

        $sku = 'E2E-' . strtoupper(bin2hex(random_bytes(3)));
        $web->get('/admin/multidb/products/create');
        $web->post('/admin/multidb/products/create', ['sku' => $sku, 'name' => 'E2E mahsulot', 'category' => 'home', 'price' => '1500,50', 'stock' => '9', 'active' => '1']);
        self::assertMatchesRegularExpression('#^/admin/multidb/products/\d+$#', $web->url(), substr($web->body(), 0, 600));
        self::assertStringContainsString('Product created.', $web->body());
        self::assertStringContainsString('1500.50', $web->body());
        $url = $web->url();

        // duplicate SKU → field error from the UNIQUE index
        $web->get('/admin/multidb/products/create');
        self::assertSame(422, $web->post('/admin/multidb/products/create', ['sku' => $sku, 'name' => 'x', 'category' => 'home', 'price' => '1', 'stock' => '1'])->getStatusCode());
        self::assertStringContainsString('SKU already exists.', $web->body());

        // edit: unchecking "Active" is sent as an absent field
        $web->get($url . '/edit');
        self::assertStringContainsString('value="' . $sku . '"', $web->body());
        $web->post($url . '/edit', ['sku' => $sku, 'name' => 'E2E mahsulot (yangi)', 'category' => 'home', 'price' => '1500.50', 'stock' => '0']);
        self::assertSame($url, $web->url());
        self::assertStringContainsString('E2E mahsulot (yangi)', $web->body());
        $compact = (string) preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $web->body());
        self::assertStringContainsString('<dt>Active</dt><dd>No</dd>', $compact);

        $web->post($url . '/delete');
        self::assertSame('/admin/multidb/products', $web->url());
        self::assertStringContainsString('Product deleted.', $web->body());
        self::assertSame(404, $web->get($url)->getStatusCode());
    }

    public function testAdminCrudOnMongoDb(): void
    {
        [$web] = AdminSession::login(['business_admin']);
        $title = 'E2E maqola ' . bin2hex(random_bytes(3));
        $web->get('/admin/multidb/articles/create');
        $web->post('/admin/multidb/articles/create', ['title' => $title, 'author' => 'E2E', 'body' => "Birinchi qator\nIkkinchi", 'tags' => 'E2E, Mongo, e2e']);
        self::assertMatchesRegularExpression('#^/admin/multidb/articles/[0-9a-f]{24}$#', $web->url(), substr($web->body(), 0, 600));
        self::assertStringContainsString('<span class="tag">e2e</span><span class="tag">mongo</span>', $web->body());
        $url = $web->url();
        $web->get('/admin/multidb/articles?q=' . rawurlencode($title));
        self::assertStringContainsString($title, $web->body());
        $web->post($url . '/delete');
        self::assertStringContainsString('Article deleted.', $web->body());
    }

    public function testWebCardsAndPermissions(): void
    {
        $user = Fixtures::user(['user']);
        $web = new WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $user['username'], 'password' => $user['password']]);

        self::assertSame(200, $web->get('/multidb')->getStatusCode(), substr($web->body(), 0, 500));
        self::assertStringContainsString('Data sources', $web->body());
        self::assertStringContainsString('data-dataset="pageviews"', $web->body());

        self::assertSame(200, $web->get('/multidb/kpis')->getStatusCode());
        self::assertStringContainsString('multidb-cards', $web->body());
        self::assertStringNotContainsString('/multidb/kpis/new', $web->body(), 'no create button without multidb.create');
        self::assertSame(403, $web->get('/multidb/kpis/new')->getStatusCode());
        self::assertSame(403, $web->get('/admin/multidb')->getStatusCode());

        $guest = new WebClient();
        $guest->get('/multidb');
        self::assertStringStartsWith('/login', $guest->url());
    }

    public function testWebCreateWithPermission(): void
    {
        [$web] = AdminSession::login(['developer']);
        $no = 'E2E-' . strtoupper(bin2hex(random_bytes(3)));
        self::assertSame(200, $web->get('/multidb/orders/new')->getStatusCode(), substr(strip_tags($web->body()), 0, 400));
        $web->post('/multidb/orders/new', ['order_no' => $no, 'customer' => 'Web E2E', 'total' => '99.90', 'currency' => 'USD', 'status' => 'delivered', 'closed_on' => '2026-10-15']);
        self::assertMatchesRegularExpression('#^/multidb/orders/[0-9a-f-]{36}$#', $web->url(), implode(' | ', array_map('strip_tags', (array) (preg_match_all('~field__error[^>]*>([^<]+)<|alert--error[^>]*>([^<]+)~', $web->body(), $mm) ? $mm[0] : []))));
        self::assertStringContainsString($no, $web->body());
        $web->post($web->url() . '/delete');
        self::assertSame('/multidb/orders', $web->url());
    }
}
