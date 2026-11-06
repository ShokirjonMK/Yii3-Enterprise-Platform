<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Console;

use App\Modules\MultiDb\Application\DatasetService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

/**
 *   ./yii multidb:install              # tables/collections in every connection + sample rows (empty datasets only)
 *   ./yii multidb:install products     # one dataset
 *   ./yii multidb:install --no-seed    # schema only
 *
 * Idempotent: existing tables and rows are kept.
 *
 * Author: ShokirjonMK
 */
#[AsCommand(name: 'multidb:install', description: 'Create the multi-database demo tables (MariaDB, PostgreSQL ×2, MongoDB, ClickHouse) and sample data')]
final class InstallCommand extends Command
{
    public function __construct(private readonly DatasetService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('dataset', InputArgument::OPTIONAL, 'Only this dataset (' . implode(', ', array_keys($this->service->datasets())) . ')');
        $this->addOption('no-seed', null, InputOption::VALUE_NONE, 'Create the schema without sample rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getArgument('dataset');
        if ($only !== null) {
            $this->service->dataset((string) $only);
        }
        $report = $this->service->install(!$input->getOption('no-seed'), $only);
        $failed = false;
        $io->table(['Dataset', 'Connection', 'Schema', 'Sample rows', 'Error'], array_map(static function (array $r) use (&$failed): array {
            $failed = $failed || $r['error'] !== null;
            return [$r['dataset'], $r['connection'], $r['error'] !== null ? '<error>FAIL</error>' : ($r['created'] ? 'created' : 'exists'), $r['seeded'] ?: '—', mb_strimwidth((string) $r['error'], 0, 100, '…')];
        }, $report));
        $failed ? $io->warning('Some datasets could not be installed (see errors).') : $io->success('Multi-database demo is ready: Admin → Multi-DB demo, /multidb, /api/v1/multidb, ./yii multidb:crud.');
        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
