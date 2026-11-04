<?php

declare(strict_types=1);

namespace App\Admin\Action\DevTools;

use App\Environment;
use App\Platform\Audit\AuditLogger;
use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Gii\EnterpriseGii;
use App\Platform\Gii\GiiPlan;
use App\Platform\Gii\PlannedFile;
use App\Platform\Gii\Spec\GiiSpecException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin · Developer Tools · Enterprise Gii (/admin/devtools/gii): form → preview (every file with status
 * new / overwrite / skip / unchanged, content preview and unified diff) → apply with confirmation.
 *
 * The page does not exist (404) in production, when flag devtools.gii is off, or when yiisoft/yii-gii (dev
 * dependency) is not installed. Route middleware requires devtools.use; apply additionally needs a recent
 * step-up authentication and an explicit confirmation; protected files are only overwritten when ticked.
 * EnterpriseGii is resolved lazily so that the controller can be built in images without yii-gii.
 */
final class GiiController
{
    public const DEFAULT_FIELDS = 'title:string:required:max=255,amount:decimal:min=0,status:enum(draft|active|closed),due_on:date,owner_id:user';

    public function __construct(
        private readonly Responder $responder,
        private readonly FeatureFlagService $flags,
        private readonly ContainerInterface $container,
        private readonly AuditLogger $audit,
    ) {}

    public static function available(FeatureFlagService $flags): bool
    {
        return !Environment::isProd() && EnterpriseGii::isInstalled() && $flags->isEnabled('devtools.gii');
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $gii = $this->gii();
        $q = $request->getQueryParams();
        return $this->page($gii, [
            'module' => self::str($q, 'module'),
            'fields' => self::str($q, 'fields') ?: self::DEFAULT_FIELDS,
            'generators' => array_keys(EnterpriseGii::GENERATORS),
        ]);
    }

    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $gii = $this->gii();
        $values = $this->values($request);
        try {
            $plan = $gii->plan($values['module'], $values['fields'], $values['generators'], $values['overwrite']);
        } catch (GiiSpecException $e) {
            return $this->page($gii, $values, errors: $e->errors(), status: 422);
        }
        return $this->page($gii, $values, $plan);
    }

    public function apply(ServerRequestInterface $request): ResponseInterface
    {
        $gii = $this->gii();
        $values = $this->values($request);
        $body = (array) $request->getParsedBody();
        try {
            $plan = $gii->plan($values['module'], $values['fields'], $values['generators'], $values['overwrite']);
        } catch (GiiSpecException $e) {
            return $this->page($gii, $values, errors: $e->errors(), status: 422);
        }
        if (($body['confirm'] ?? '') !== '1') {
            return $this->page($gii, $values, $plan, ['confirm' => ['Tick "I reviewed the preview" to write the files.']], 422);
        }
        $gii->apply($plan);
        $written = array_values(array_map(static fn(PlannedFile $f) => $f->path(), array_filter($plan->files, static fn(PlannedFile $f) => in_array($f->result, ['created', 'overwrote'], true))));
        $this->audit->record('devtools.gii.applied', 'devtools', $plan->module, null, null, [
            'module' => $plan->module,
            'generators' => $plan->generators,
            'written' => $written,
            'overwritten' => $values['overwrite'],
            'errors' => count(array_filter($plan->files, static fn(PlannedFile $f) => $f->error !== null)),
        ], category: 'config', severity: 'notice');
        if ($plan->hasErrors()) {
            $this->responder->error('Some files could not be written.');
        } else {
            $this->responder->success(sprintf('%d file(s) written. Next: ./yii migrate:up && ./yii rbac:sync', count($written)));
        }
        return $this->page($gii, $values, $plan);
    }

    private function page(EnterpriseGii $gii, array $values, ?GiiPlan $plan = null, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->responder->render('admin/gii/index', [
            'values' => $values + ['overwrite' => []],
            'catalog' => $gii->catalog(),
            'plan' => $plan,
            'errors' => $errors,
        ], status: $status);
    }

    /** @return array{module:string, fields:string, generators:list<string>, overwrite:list<string>} */
    private function values(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $list = static fn(mixed $v): array => array_values(array_filter(array_map(static fn($x) => is_string($x) ? trim($x) : '', is_array($v) ? $v : []), static fn($x) => $x !== ''));
        $generators = $list($body['generators'] ?? []);
        return [
            'module' => self::str($body, 'module'),
            'fields' => mb_substr(self::str($body, 'fields'), 0, 4000),
            'generators' => $generators === [] ? ['all'] : array_values(array_intersect($generators, array_keys(EnterpriseGii::GENERATORS))),
            'overwrite' => $list($body['overwrite'] ?? []),
        ];
    }

    private function gii(): EnterpriseGii
    {
        if (!self::available($this->flags)) {
            throw new NotFoundException('Page not found.');
        }
        return $this->container->get(EnterpriseGii::class);
    }

    private static function str(array $a, string $k): string
    {
        return isset($a[$k]) && is_scalar($a[$k]) ? trim((string) $a[$k]) : '';
    }
}
