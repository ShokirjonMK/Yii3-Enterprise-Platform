<?php

declare(strict_types=1);

namespace App\Platform\Identity\Sso;

use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Security\Encrypter;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final class SsoProviderRepository
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Encrypter $encrypter,
    ) {}

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_map(fn($r) => $this->hydrate($r, false), (new Query($this->db))->from('sso_providers')->orderBy(['sort_order' => SORT_ASC, 'name' => SORT_ASC])->all());
    }

    /** @return list<array{code:string,name:string,type:string}> */
    public function enabledForLogin(): array
    {
        try {
            return (new Query($this->db))->select(['code', 'name', 'type'])->from('sso_providers')
                ->where(['enabled' => true])->andWhere(['not in', 'status', ['failed', 'maintenance', 'unconfigured']])
                ->orderBy(['sort_order' => SORT_ASC, 'name' => SORT_ASC])->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function find(string $code, bool $withSecret = false): ?array
    {
        $row = (new Query($this->db))->from('sso_providers')->where(['code' => $code])->one();
        return $row === null ? null : $this->hydrate($row, $withSecret);
    }

    private function hydrate(array $r, bool $withSecret): array
    {
        $r['config'] = Sql::json($r['config']);
        $r['redirect_allowlist'] = Sql::json($r['redirect_allowlist']);
        $r['enabled'] = Sql::bool($r['enabled']);
        $r['auto_provision'] = Sql::bool($r['auto_provision']);
        $r['has_secret'] = !empty($r['secret_enc']);
        $r['secret'] = $withSecret && !empty($r['secret_enc']) ? $this->encrypter->decrypt((string) $r['secret_enc'], 'sso') : null;
        unset($r['secret_enc']);
        return $r;
    }
}
