<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Admin;

use App\Modules\MultiDb\Application\DatasetService;
use App\Modules\MultiDb\Domain\Dataset;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Admin · Multi-DB demo: overview of all datasets (connection, engine, rows, database-computed statistics),
 * install / seed, and a full CRUD table for every dataset (search, filters, sort, paging, 422 forms, delete).
 *
 * Author: ShokirjonMK
 */
final class MultiDbController
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly Responder $responder,
        private readonly DatasetService $service,
        private readonly CurrentRoute $route,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->responder->render('@modules/MultiDb/Admin/views/index', ['overview' => $this->service->overview()]);
    }

    public function install(): ResponseInterface
    {
        $report = $this->service->install();
        $failed = array_filter($report, static fn($r) => $r['error'] !== null);
        $seeded = array_sum(array_column($report, 'seeded'));
        if ($failed !== []) {
            $this->responder->error('Not installed: ' . implode(', ', array_column($failed, 'dataset')) . '. ' . (string) reset($failed)['error']);
        } else {
            $this->responder->success("Demo tables are ready ($seeded sample rows loaded).");
        }
        return $this->responder->redirect('admin.multidb');
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $d = $this->dataset();
        $params = $request->getQueryParams();
        $q = self::str($params, 'q');
        $filters = [];
        foreach ($d->filters as $f) {
            $filters[$f] = self::str($params, $f);
        }
        $error = null;
        try {
            $page = $this->service->search($d, $q, $filters, (int) ($params['page'] ?? 1), self::PER_PAGE, self::str($params, 'sort') ?: null);
            $stats = $this->service->store($d)->stats();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $page = new \App\Shared\Infrastructure\Database\Page([], 0, 1, self::PER_PAGE, $d->defaultSort);
            $stats = [];
            $error = $e->getMessage();
        }
        return $this->responder->render('@modules/MultiDb/Admin/views/list', [
            'd' => $d,
            'page' => $page,
            'stats' => $stats,
            'q' => $q,
            'filters' => $filters,
            'query' => array_filter(['q' => $q, ...$filters, 'sort' => self::str($params, 'sort')], static fn($v) => $v !== ''),
            'error' => $error,
        ]);
    }

    public function view(): ResponseInterface
    {
        $d = $this->dataset();
        $row = $this->service->get($d, $this->id());
        return $this->responder->render('@modules/MultiDb/Admin/views/view', ['d' => $d, 'row' => $row, 'title' => $this->service->title($d, $row)]);
    }

    public function createForm(): ResponseInterface
    {
        return $this->form($this->dataset(), null, [], []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $d = $this->dataset();
        $input = self::input($d, $request);
        try {
            $row = $this->service->create($d, $input);
        } catch (ValidationException $e) {
            return $this->form($d, null, $input, $e->errors(), 422);
        }
        $this->responder->success(ucfirst($d->singular) . ' created.');
        return $this->responder->redirect('admin.multidb.view', ['dataset' => $d->code, 'id' => $row['id']]);
    }

    public function editForm(): ResponseInterface
    {
        $d = $this->dataset();
        $row = $this->service->get($d, $this->id());
        $values = [];
        foreach ($d->fields as $f) {
            $values[$f->name] = $f->formValue($row[$f->name]);
        }
        return $this->form($d, $row, $values, []);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $d = $this->dataset();
        $input = self::input($d, $request);
        try {
            $row = $this->service->update($d, $this->id(), $input);
        } catch (ValidationException $e) {
            return $this->form($d, $this->service->get($d, $this->id()), $input, $e->errors(), 422);
        }
        $this->responder->success(ucfirst($d->singular) . ' saved.');
        return $this->responder->redirect('admin.multidb.view', ['dataset' => $d->code, 'id' => $row['id']]);
    }

    public function delete(): ResponseInterface
    {
        $d = $this->dataset();
        $this->service->delete($d, $this->id());
        $this->responder->success(ucfirst($d->singular) . ' deleted.');
        return $this->responder->redirect('admin.multidb.list', ['dataset' => $d->code]);
    }

    // ------------------------------------------------------------------ helpers

    private function form(Dataset $d, ?array $row, array $values, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->render('@modules/MultiDb/Admin/views/form', [
            'd' => $d,
            'row' => $row,
            'title' => $row !== null ? $this->service->title($d, $row) : null,
            'values' => $values,
            'errors' => $errors,
        ], status: $status);
    }

    /** Form body → field values. Unchecked checkboxes are absent from the POST body, so BOOL fields default to "". */
    public static function input(Dataset $d, ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $in = [];
        foreach ($d->fields as $f) {
            $v = $body[$f->name] ?? '';
            $in[$f->name] = is_scalar($v) ? (string) $v : '';
        }
        return $in;
    }

    private function dataset(): Dataset
    {
        return $this->service->dataset((string) $this->route->getArgument('dataset'));
    }

    private function id(): string
    {
        $id = (string) $this->route->getArgument('id');
        if ($id === '') {
            throw new NotFoundException();
        }
        return $id;
    }

    private static function str(array $params, string $key): string
    {
        $v = $params[$key] ?? '';
        return is_string($v) ? trim($v) : '';
    }
}
