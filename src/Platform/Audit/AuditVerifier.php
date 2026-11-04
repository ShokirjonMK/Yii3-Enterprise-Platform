<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Shared\Infrastructure\Security\Encrypter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Recomputes the HMAC of stored audit rows to detect tampering.
 */
final class AuditVerifier
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Encrypter $encrypter,
    ) {}

    /**
     * @return array{checked:int, invalid:list<int>}
     */
    public function verify(?string $from = null, ?string $to = null, int $limit = 10000): array
    {
        $query = (new Query($this->db))->from('audit_log')->orderBy(['id' => SORT_ASC])->limit($limit);
        if ($from !== null) {
            $query->andWhere(['>=', 'occurred_at', $from]);
        }
        if ($to !== null) {
            $query->andWhere(['<', 'occurred_at', $to]);
        }
        $checked = 0;
        $invalid = [];
        foreach ($query->each(500) as $row) {
            $checked++;
            $expected = $this->encrypter->hmac(AuditLogger::canonical($row), 'audit');
            if (!hash_equals($expected, (string) $row['row_hmac'])) {
                $invalid[] = (int) $row['id'];
            }
        }
        return ['checked' => $checked, 'invalid' => $invalid];
    }
}
