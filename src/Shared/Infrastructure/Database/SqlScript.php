<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database;

use PDO;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;
use Yiisoft\Db\Migration\MigrationBuilder;

/**
 * Runs a multi-statement SQL script (DDL, functions, triggers) in migrations.
 * Native prepared statements (ATTR_EMULATE_PREPARES=false) do not allow several commands per statement,
 * so scripts are executed with PDO::exec on the active connection inside the migration transaction.
 */
final class SqlScript
{
    public static function run(MigrationBuilder $b, string $sql): void
    {
        $db = $b->getDb();
        if (!$db instanceof PdoConnectionInterface) {
            $b->execute($sql);
            return;
        }
        $pdo = $db->getActivePdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec($sql);
        $db->getSchema()->refresh();
    }
}
