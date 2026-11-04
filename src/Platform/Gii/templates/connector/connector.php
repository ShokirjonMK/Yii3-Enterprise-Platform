<?php

declare(strict_types=1);

/**
 * Template: integration connector (src/Modules/<Module>/Infrastructure/<Module>Connector.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Infrastructure;

use App\Modules\{{Module}}\Domain\{{Entity}};
use App\Platform\Integrations\Exception\IntegrationException;
use App\Platform\Integrations\IntegrationService;
use App\Shared\Domain\Exception\NotFoundException;

/**
 * Outbound connector of the {{label}} module to the external system registered as integration "__CODE__".
 * All calls go through the platform HttpClient (IntegrationService::call): SSRF guard + IP pinning,
 * timeout / connect timeout, retries with exponential backoff + jitter (idempotent methods only),
 * circuit breaker, rate limit, auth (bearer/basic/api_key/hmac), redacted request logs and metrics.
 * Timeout/retry values come from the integrations row (seeded: 5 s, 3 retries, 300 ms backoff) and are
 * edited in Admin → Integrations; the row is disabled until configured ("Not configured").
 */
final class {{Module}}Connector
{
    public const INTEGRATION = '__CODE__';

    public function __construct(private readonly IntegrationService $integrations) {}

    /** True when the integration exists, is enabled and has a base URL. */
    public function isConfigured(): bool
    {
        try {
            $cfg = $this->integrations->config(self::INTEGRATION);
        } catch (NotFoundException) {
            return false;
        }
        return $cfg->enabled && $cfg->baseUrl !== '';
    }

    /**
     * Upserts the record in the external system (PUT is idempotent → safe to retry).
     *
     * @return array<mixed> decoded JSON response
     * @throws IntegrationException (NotConfiguredException when disabled / not configured)
     */
    public function push({{Entity}} $entity): array
    {
        $response = $this->integrations->call(self::INTEGRATION, 'PUT', '/{{slug}}/' . rawurlencode($entity->id), [
            'json' => $entity->toArray(),
            'headers' => ['Idempotency-Key' => $entity->id . ':' . $entity->version()],
        ]);
        $body = (string) $response->getBody();
        return trim($body) === '' ? [] : (array) json_decode($body, true);
    }

    /**
     * Reads the external representation of a record.
     *
     * @return array<mixed>
     * @throws IntegrationException
     */
    public function fetch(string $id): array
    {
        $body = (string) $this->integrations->call(self::INTEGRATION, 'GET', '/{{slug}}/' . rawurlencode($id))->getBody();
        return trim($body) === '' ? [] : (array) json_decode($body, true);
    }
}

PHP, ['__CODE__' => $m->code]);
