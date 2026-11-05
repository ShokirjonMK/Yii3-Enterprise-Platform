<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Database\Connections\DatabaseDriver;
use App\Platform\Database\Connections\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Lists every named database connection (platform, .env, Admin → Databases) and tests it.
 *
 *   ./yii db:connections            # all
 *   ./yii db:connections docs       # one
 *
 * Exit code 1 when an enabled connection fails (usable in deploy checks).
 *
 * Author: ShokirjonMK
 */
#[AsCommand(name: 'db:connections', description: 'List and test all named database connections (any engine)')]
final class DbConnectionsCommand extends Command
{
    public function __construct(private readonly DatabaseManager $dbs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Only this connection');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getArgument('name');
        $rows = [];
        $failed = false;
        foreach ($this->dbs->definitions() as $name => $d) {
            if ($only !== null && $name !== $only) {
                continue;
            }
            $r = $d->enabled ? $this->dbs->test($d) : ['ok' => false, 'latency_ms' => 0, 'version' => null, 'error' => 'disabled'];
            $failed = $failed || ($d->enabled && !$r['ok']);
            $rows[] = [
                $name, DatabaseDriver::label($d->driver), $d->source, $d->target() . ($d->readOnly ? ' (read-only)' : ''),
                $r['ok'] ? '<info>OK</info> ' . $r['latency_ms'] . ' ms' : '<error>FAIL</error>',
                $r['ok'] ? (string) $r['version'] : mb_strimwidth((string) $r['error'], 0, 120, '…'),
            ];
        }
        if ($rows === []) {
            $io->error($only !== null ? "Connection \"$only\" not found." : 'No connections.');
            return ExitCode::DATAERR;
        }
        $io->table(['Name', 'Engine', 'Source', 'Target', 'Status', 'Version / error'], $rows);
        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
