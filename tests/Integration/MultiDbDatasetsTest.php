<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Modules\MultiDb\Application\DatasetService;
use App\Modules\MultiDb\Infrastructure\DemoSchema;
use App\Platform\Database\Connections\DatabaseManager;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The same CRUD against five real databases: MariaDB (shop), PostgreSQL (analytics, archive), MongoDB (docs),
 * ClickHouse (events). Each case creates its own uniquely marked row and removes it. A dataset whose connection
 * is not configured is skipped.
 */
final class MultiDbDatasetsTest extends IntegrationTestCase
{
    private DatasetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->get(DatasetService::class);
    }

    /** @return iterable<string, array{string, string, mixed}> dataset, field to change, new value */
    public static function datasets(): iterable
    {
        yield 'MariaDB products' => ['products', 'stock', 3];
        yield 'PostgreSQL kpis' => ['kpis', 'value', '12.50'];
        yield 'PostgreSQL orders' => ['orders', 'status', 'refunded'];
        yield 'MongoDB articles' => ['articles', 'tags', ['ops', 'test']];
        yield 'ClickHouse pageviews' => ['pageviews', 'duration_ms', 4321];
    }

    #[DataProvider('datasets')]
    public function testCrudRoundTrip(string $code, string $field, mixed $newValue): void
    {
        $d = $this->service->dataset($code);
        $this->requireConnection($d->connection);
        $this->service->install(false, $code);

        $marker = 'it' . bin2hex(random_bytes(4));
        $input = $this->uniqueInput($code, $marker);
        $row = $this->service->create($d, $input);
        try {
            self::assertTrue($d->validId($row['id']), 'id format ' . $d->idType . ': ' . $row['id']);
            self::assertSame($row, $this->service->get($d, $row['id']));

            $found = $this->service->search($d, $marker, [], 1, 10);
            self::assertSame([$row['id']], array_column($found->items, 'id'), 'search finds the new row by its marker');

            $updated = $this->service->update($d, $row['id'], [$field => $newValue], true);
            self::assertSame($d->field($field)->present($newValue), $updated[$field]);
            foreach ($d->fields as $f) {
                if ($f->name !== $field) {
                    self::assertSame($row[$f->name], $updated[$f->name], "PATCH keeps $f->name");
                }
            }
        } finally {
            $this->service->delete($d, $row['id']);
        }
        try {
            $this->service->get($d, $row['id']);
            self::fail('deleted row is gone');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }
        self::assertTrue((new \Yiisoft\Db\Query\Query($this->db))->from('audit_log')->where(['action' => 'multidb.' . $code . '.deleted', 'entity_id' => $row['id']])->exists(), 'audited in the platform database');
    }

    #[DataProvider('datasets')]
    public function testValidationAndUnknownIds(string $code): void
    {
        $d = $this->service->dataset($code);
        $this->requireConnection($d->connection);
        try {
            $this->service->create($d, []);
            self::fail('required fields are enforced');
        } catch (ValidationException $e) {
            $required = array_values(array_map(static fn($f) => $f->name, array_filter($d->fields, static fn($f) => $f->required)));
            self::assertSame($required, array_keys($e->errors()));
        }
        foreach (['not-an-id', $d->idType === 'int' ? '999999999' : ($d->idType === 'uuid' ? '00000000-0000-4000-8000-000000000000' : str_repeat('a', 24))] as $id) {
            try {
                $this->service->get($d, $id);
                self::fail("$id must be 404");
            } catch (NotFoundException) {
                self::assertTrue(true);
            }
        }
    }

    public function testUniqueConstraintBecomesFieldError(): void
    {
        $d = $this->service->dataset('products');
        $this->requireConnection('shop');
        $this->service->install(false, 'products');
        $row = $this->service->create($d, $this->uniqueInput('products', 'dup' . bin2hex(random_bytes(3))));
        try {
            $this->service->create($d, ['sku' => $row['sku']] + $this->uniqueInput('products', 'x'));
            self::fail('duplicate SKU rejected');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('sku', $e->errors());
        } finally {
            $this->service->delete($d, $row['id']);
        }
    }

    public function testFiltersAndSortAreEngineNative(): void
    {
        $this->requireConnection('events');
        $d = $this->service->dataset('pageviews');
        $this->service->install(false, 'pageviews');
        $marker = '/it-' . bin2hex(random_bytes(4));
        $ids = [];
        foreach ([[100, 'mobile'], [300, 'desktop'], [200, 'mobile']] as [$ms, $device]) {
            $ids[] = $this->service->create($d, ['viewed_at' => '2026-11-01 10:00:00', 'path' => $marker, 'country' => 'UZ', 'device' => $device, 'duration_ms' => $ms])['id'];
        }
        try {
            $page = $this->service->search($d, $marker, ['device' => 'mobile'], 1, 10, '-duration_ms');
            self::assertSame([200, 100], array_column($page->items, 'duration_ms'));
            self::assertSame(2, $page->total);
            $this->expectException(ValidationException::class);
            $this->service->search($d, '', ['device' => 'fax'], 1, 10);
        } finally {
            foreach ($ids as $id) {
                $this->service->delete($d, $id);
            }
        }
    }

    private function requireConnection(string $name): void
    {
        $dbs = $this->get(DatabaseManager::class);
        if (!$dbs->has($name) || !$dbs->test($dbs->definition($name))['ok']) {
            self::markTestSkipped("Connection \"$name\" is not available.");
        }
    }

    /** First sample row with unique values made unique by the marker. */
    private function uniqueInput(string $code, string $marker): array
    {
        $in = DemoSchema::seed($code)[0];
        return match ($code) {
            'products' => ['sku' => strtoupper(substr($marker, 0, 30)), 'name' => 'Test ' . $marker] + $in,
            'kpis' => ['period' => sprintf('19%02d-%02d-01', random_int(0, 99), random_int(1, 12)), 'note' => $marker] + $in,
            'orders' => ['order_no' => 'T-' . $marker, 'customer' => 'Customer ' . $marker] + $in,
            'articles' => ['title' => 'Article ' . $marker] + $in,
            'pageviews' => ['path' => '/' . $marker] + $in,
        };
    }
}
