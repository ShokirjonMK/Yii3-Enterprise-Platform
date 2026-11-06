<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Web;

use App\Modules\MultiDb\Admin\MultiDbController as AdminController;
use App\Modules\MultiDb\Application\DatasetService;
use App\Modules\MultiDb\Domain\Dataset;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Web (signed-in users) · Multi-DB demo as a catalogue: data-source tiles, card grid per dataset, detail page;
 * create / edit / delete for users with the multidb.* permissions. Same DatasetService as Admin and the API.
 *
 * Author: ShokirjonMK
 */
final class MultiDbController
{
    public const PER_PAGE = 12;

    public function __construct(
        private readonly Responder $responder,
        private readonly DatasetService $service,
        private readonly CurrentRoute $route,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->responder->render('@modules/MultiDb/Web/views/index', ['overview' => $this->service->overview(false)], 'web');
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $d = $this->dataset();
        $params = $request->getQueryParams();
        $q = is_string($params['q'] ?? null) ? trim($params['q']) : '';
        $page = $this->service->search($d, $q, [], (int) ($params['page'] ?? 1), self::PER_PAGE, is_string($params['sort'] ?? null) ? $params['sort'] : null);
        return $this->responder->render('@modules/MultiDb/Web/views/list', [
            'd' => $d,
            'page' => $page,
            'q' => $q,
            'query' => array_filter(['q' => $q], static fn($v) => $v !== ''),
            'datasets' => $this->service->datasets(),
        ], 'web');
    }

    public function view(): ResponseInterface
    {
        $d = $this->dataset();
        $row = $this->service->get($d, (string) $this->route->getArgument('id'));
        return $this->responder->render('@modules/MultiDb/Web/views/view', ['d' => $d, 'row' => $row, 'title' => $this->service->title($d, $row)], 'web');
    }

    public function createForm(): ResponseInterface
    {
        return $this->form($this->dataset(), null, [], []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $d = $this->dataset();
        $input = AdminController::input($d, $request);
        try {
            $row = $this->service->create($d, $input);
        } catch (ValidationException $e) {
            return $this->form($d, null, $input, $e->errors(), 422);
        }
        $this->responder->success(ucfirst($d->singular) . ' created.');
        return $this->responder->redirect('web.multidb.view', ['dataset' => $d->code, 'id' => $row['id']]);
    }

    public function editForm(): ResponseInterface
    {
        $d = $this->dataset();
        $row = $this->service->get($d, (string) $this->route->getArgument('id'));
        $values = [];
        foreach ($d->fields as $f) {
            $values[$f->name] = $f->formValue($row[$f->name]);
        }
        return $this->form($d, $row, $values, []);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $d = $this->dataset();
        $id = (string) $this->route->getArgument('id');
        $input = AdminController::input($d, $request);
        try {
            $this->service->update($d, $id, $input);
        } catch (ValidationException $e) {
            return $this->form($d, $this->service->get($d, $id), $input, $e->errors(), 422);
        }
        $this->responder->success(ucfirst($d->singular) . ' saved.');
        return $this->responder->redirect('web.multidb.view', ['dataset' => $d->code, 'id' => $id]);
    }

    public function delete(): ResponseInterface
    {
        $d = $this->dataset();
        $this->service->delete($d, (string) $this->route->getArgument('id'));
        $this->responder->success(ucfirst($d->singular) . ' deleted.');
        return $this->responder->redirect('web.multidb.list', ['dataset' => $d->code]);
    }

    private function form(Dataset $d, ?array $row, array $values, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->render('@modules/MultiDb/Web/views/form', [
            'd' => $d,
            'row' => $row,
            'title' => $row !== null ? $this->service->title($d, $row) : null,
            'values' => $values,
            'errors' => $errors,
        ], 'web', $status);
    }

    private function dataset(): Dataset
    {
        return $this->service->dataset((string) $this->route->getArgument('dataset'));
    }
}
