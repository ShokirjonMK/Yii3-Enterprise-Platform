<?php

declare(strict_types=1);

namespace App\Admin\Databases;

use App\Platform\Access\AccessService;
use App\Platform\Database\Connections\ConnectionStore;
use App\Platform\Database\Connections\DatabaseDriver;
use App\Platform\Database\Connections\DatabaseInspector;
use App\Platform\Database\Connections\DatabaseManager;
use App\Platform\Database\Connections\QueryConsole;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * Databases — every named connection of the platform in one place, whatever the engine (PostgreSQL, MySQL/MariaDB,
 * SQLite, SQL Server, Oracle, MongoDB, ClickHouse):
 *  - databases.view:   list, live status, server version, size, tables/collections,
 *  - databases.manage: add / edit / enable-disable / delete panel connections (step-up; .env ones are read-only),
 *  - databases.query:  read-only console (step-up), databases.write: data-changing statements (superadmin).
 * Everything is audited; passwords are write-only.
 */
final class DatabasesController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ConnectionStore $store,
        private readonly DatabaseManager $dbs,
        private readonly DatabaseInspector $inspector,
        private readonly QueryConsole $console,
        private readonly AccessService $access,
        private readonly CurrentUser $currentUser,
    ) {}

    public function index(): ResponseInterface
    {
        $defs = $this->store->all(true);
        $engines = [];
        foreach ($defs as $d) {
            $engines[$d->driver] = ($engines[$d->driver] ?? 0) + 1;
        }
        $drivers = [];
        foreach (DatabaseDriver::all() as $driver) {
            $drivers[$driver] = DatabaseDriver::unavailableReason($driver);
        }
        return $this->responder->render('@admin/Databases/views/index', ['defs' => $defs, 'engines' => $engines, 'drivers' => $drivers]);
    }

    /** HTMX: live status badge of one connection (loaded lazily so a slow host never blocks the list). */
    public function status(CurrentRoute $route): ResponseInterface
    {
        $d = $this->store->find($this->name($route));
        $result = $d->enabled ? $this->dbs->test($d) : null;
        return $this->responder->fragment('@admin/Databases/views/_status', ['result' => $result]);
    }

    public function show(CurrentRoute $route): ResponseInterface
    {
        $d = $this->store->find($this->name($route));
        return $this->responder->render('@admin/Databases/views/show', [
            'd' => $d,
            'info' => $d->enabled ? $this->inspector->inspect($d->name) : null,
        ]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $driver = (string) ($request->getQueryParams()['driver'] ?? DatabaseDriver::PGSQL);
        return $this->form(null, ['driver' => DatabaseDriver::exists($driver) ? $driver : DatabaseDriver::PGSQL, 'enabled' => '1'], []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $in = (array) $request->getParsedBody();
        try {
            $d = $this->store->create($this->actor(), $in);
        } catch (ValidationException $e) {
            return $this->form(null, $in, $e->errors(), 422);
        }
        $this->responder->success('Connection saved. Use it in code through DatabaseManager by its name.');
        return $this->responder->redirect('admin.databases.show', ['name' => $d->name]);
    }

    public function edit(CurrentRoute $route): ResponseInterface
    {
        $d = $this->store->find($this->name($route));
        if (!$d->editable()) {
            $this->responder->error('This connection is defined in .env (or is the platform database) and cannot be changed in the panel.');
            return $this->responder->redirect('admin.databases.show', ['name' => $d->name]);
        }
        $values = $d->toPublicArray();
        $values['port'] = $d->port > 0 ? $d->port : '';
        $values['database'] = $d->driver === DatabaseDriver::SQLITE ? basename((string) ($d->option('path') ?: $d->database)) : $d->database;
        $opts = $d->options;
        unset($opts['path']);
        $values['options'] = $opts === [] ? '' : json_encode($opts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $values['version'] = $d->version;
        return $this->form($d->name, $values, []);
    }

    public function update(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $name = $this->name($route);
        $in = (array) $request->getParsedBody();
        try {
            $this->store->update($this->actor(), $name, $in, isset($in['version']) && $in['version'] !== '' ? (int) $in['version'] : null);
        } catch (ValidationException $e) {
            return $this->form($name, $in, $e->errors(), 422);
        }
        $this->dbs->forget($name);
        $this->responder->success('Connection saved.');
        return $this->responder->redirect('admin.databases.show', ['name' => $name]);
    }

    /** HTMX: test the form values before saving (the stored password is used when the field is left empty). */
    public function testDraft(ServerRequestInterface $request): ResponseInterface
    {
        $in = (array) $request->getParsedBody();
        $existing = ($in['existing'] ?? '') !== '' ? (string) $in['existing'] : null;
        try {
            $result = $this->dbs->test($this->store->draft($in, $existing));
        } catch (ValidationException $e) {
            $result = ['ok' => false, 'latency_ms' => 0, 'version' => null, 'error' => implode(' ', array_merge(...array_values($e->errors())))];
        }
        return $this->responder->fragment('@admin/Databases/views/_test', ['result' => $result]);
    }

    public function test(CurrentRoute $route): ResponseInterface
    {
        $d = $this->store->find($this->name($route));
        return $this->responder->fragment('@admin/Databases/views/_test', ['result' => $this->dbs->test($d)]);
    }

    public function toggle(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $name = $this->name($route);
        $enable = (((array) $request->getParsedBody())['enabled'] ?? '0') === '1';
        $this->store->setEnabled($this->actor(), $name, $enable);
        $this->dbs->forget($name);
        $this->responder->success($enable ? 'Connection enabled.' : 'Connection disabled. Code using it gets an error until it is enabled again.');
        return $this->responder->redirect('admin.databases.show', ['name' => $name]);
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $name = $this->name($route);
        $this->store->delete($this->actor(), $name);
        $this->dbs->forget($name);
        $this->responder->success('Connection deleted. The database itself was not touched.');
        return $this->responder->redirect('admin.databases');
    }

    public function console(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $d = $this->store->find($this->name($route));
        $object = (string) ($request->getQueryParams()['object'] ?? '');
        return $this->responder->render('@admin/Databases/views/console', [
            'd' => $d,
            'statement' => $object !== '' ? self::sample($d->driver, $object) : '',
            'write' => false,
            'limit' => 100,
            'result' => null,
            'canWrite' => $this->canWrite($d->readOnly),
        ]);
    }

    public function run(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $d = $this->store->find($this->name($route));
        $in = (array) $request->getParsedBody();
        $statement = (string) ($in['statement'] ?? '');
        $write = ($in['mode'] ?? 'read') === 'write';
        $limit = (int) ($in['limit'] ?? 100);
        if ($write && !$this->canWrite($d->readOnly)) {
            throw new AccessDeniedException('Write mode needs the databases.write permission (superadmin) on a connection that is not read-only.');
        }
        $params = ['d' => $d, 'statement' => $statement, 'write' => $write, 'limit' => $limit, 'canWrite' => $this->canWrite($d->readOnly), 'errors' => []];
        try {
            $params['result'] = $this->console->run($this->actor(), $d->name, $statement, $write, $limit);
        } catch (ValidationException $e) {
            return $this->responder->render('@admin/Databases/views/console', $params + ['result' => null, 'errors' => $e->errors()], status: 422);
        }
        return $this->responder->render('@admin/Databases/views/console', $params);
    }

    private function form(?string $name, array $values, array $errors, int $status = 200): ResponseInterface
    {
        $drivers = [];
        foreach (DatabaseDriver::options() as $k => $label) {
            $drivers[$k] = ['label' => $label, 'port' => DatabaseDriver::defaultPort($k), 'unavailable' => DatabaseDriver::unavailableReason($k)];
        }
        return $this->responder->render('@admin/Databases/views/form', [
            'name' => $name, 'values' => $values, 'errors' => $errors, 'drivers' => $drivers,
            'hasPassword' => $name !== null && $this->store->find($name)->password !== '',
        ], status: $status);
    }

    private function canWrite(bool $readOnly): bool
    {
        return !$readOnly && $this->access->can($this->actor(), 'databases.write');
    }

    private static function sample(string $driver, string $object): string
    {
        if (DatabaseDriver::family($driver) === 'document') {
            return json_encode(['collection' => $object, 'find' => new \stdClass(), 'sort' => ['_id' => -1]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        $quoted = match ($driver) {
            DatabaseDriver::MYSQL, DatabaseDriver::CLICKHOUSE => implode('.', array_map(static fn($p) => '`' . str_replace('`', '``', $p) . '`', explode('.', $object))),
            DatabaseDriver::SQLSRV => implode('.', array_map(static fn($p) => '[' . str_replace(']', ']]', $p) . ']', explode('.', $object))),
            default => implode('.', array_map(static fn($p) => '"' . str_replace('"', '""', $p) . '"', explode('.', $object))),
        };
        return match ($driver) {
            DatabaseDriver::SQLSRV => "SELECT TOP 100 * FROM $quoted",
            DatabaseDriver::OCI => "SELECT * FROM $quoted FETCH FIRST 100 ROWS ONLY",
            default => "SELECT * FROM $quoted LIMIT 100",
        };
    }

    private function name(CurrentRoute $route): string
    {
        return (string) $route->getArgument('name');
    }

    private function actor(): string
    {
        return (string) $this->currentUser->getId();
    }
}
