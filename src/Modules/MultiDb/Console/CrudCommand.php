<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Console;

use App\Modules\MultiDb\Application\DatasetService;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

/**
 * CRUD of the demo datasets from the command line (same validation and audit as Admin / API):
 *
 *   ./yii multidb:crud                                         # datasets with row counts
 *   ./yii multidb:crud products list --q=phone --filter=category=electronics --sort=-price --page=2
 *   ./yii multidb:crud kpis show 6f1c…
 *   ./yii multidb:crud orders create --set order_no=ORD-9 --set customer="Ali" --set total=100 --set currency=UZS --set status=delivered --set closed_on=2026-11-01
 *   ./yii multidb:crud articles update 672b… --set tags="ops, guide" --set published=1
 *   ./yii multidb:crud pageviews delete 0a8e…
 *   ./yii multidb:crud pageviews stats
 *   … --json                                                   # machine-readable output
 *
 * Author: ShokirjonMK
 */
#[AsCommand(name: 'multidb:crud', description: 'List / show / create / update / delete rows of a multi-database demo dataset')]
final class CrudCommand extends Command
{
    private const ACTIONS = ['list', 'show', 'create', 'update', 'delete', 'stats'];

    public function __construct(private readonly DatasetService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dataset', InputArgument::OPTIONAL, 'Dataset code (' . implode(', ', array_keys($this->service->datasets())) . ')')
            ->addArgument('action', InputArgument::OPTIONAL, implode(' | ', self::ACTIONS), 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'Row id (show, update, delete)')
            ->addOption('set', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'field=value (create, update)')
            ->addOption('q', null, InputOption::VALUE_REQUIRED, 'Search text (list)', '')
            ->addOption('filter', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'field=value exact filter (list)')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort key, "-" prefix for descending (list)')
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Page (list)', '1')
            ->addOption('per-page', null, InputOption::VALUE_REQUIRED, 'Rows per page (list)', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $code = $input->getArgument('dataset');
        try {
            if ($code === null) {
                return $this->overview($io, $output, $json);
            }
            $d = $this->service->dataset((string) $code);
            $action = (string) $input->getArgument('action');
            if (!in_array($action, self::ACTIONS, true)) {
                $io->error('Unknown action "' . $action . '". Use: ' . implode(', ', self::ACTIONS) . '.');
                return ExitCode::USAGE;
            }
            $id = (string) $input->getArgument('id');
            if (in_array($action, ['show', 'update', 'delete'], true) && $id === '') {
                $io->error('The "' . $action . '" action needs an id.');
                return ExitCode::USAGE;
            }
            $set = self::pairs((array) $input->getOption('set'));
            switch ($action) {
                case 'list':
                    $page = $this->service->search($d, (string) $input->getOption('q'), self::pairs((array) $input->getOption('filter')), (int) $input->getOption('page'), max(1, min(200, (int) $input->getOption('per-page'))), $input->getOption('sort'));
                    if ($json) {
                        $output->writeln(self::json(['data' => $page->items, 'meta' => $page->meta()]));
                        break;
                    }
                    $io->title($d->label . ' · ' . $d->connection . ' (' . $d->engineLabel() . ')');
                    $io->table(['id', ...$d->columns], array_map(static fn(array $r) => [
                        mb_strimwidth($r['id'], 0, 13, '…'),
                        ...array_map(static fn(string $c) => mb_strimwidth($d->field($c)?->display($r[$c]) ?? '', 0, 40, '…'), $d->columns),
                    ], $page->items));
                    $io->writeln(sprintf('Page %d of %d · %d rows · sort %s', $page->page, $page->pages(), $page->total, $page->sort));
                    break;
                case 'show':
                    $this->printRow($io, $output, $d->label, $this->service->get($d, $id), $json);
                    break;
                case 'create':
                    $row = $this->service->create($d, $set);
                    $json ? $output->writeln(self::json($row)) : $io->success('Created ' . $d->singular . ' ' . $row['id'] . '.');
                    break;
                case 'update':
                    if ($set === []) {
                        $io->error('Nothing to change: pass --set field=value.');
                        return ExitCode::USAGE;
                    }
                    $this->printRow($io, $output, 'Updated', $this->service->update($d, $id, $set, true), $json);
                    break;
                case 'delete':
                    $this->service->delete($d, $id);
                    $json ? $output->writeln(self::json(['deleted' => $id])) : $io->success('Deleted ' . $d->singular . ' ' . $id . '.');
                    break;
                case 'stats':
                    $stats = $this->service->store($d)->stats();
                    $json ? $output->writeln(self::json($stats)) : $io->table(['Metric', 'Value'], array_map(static fn($s) => [$s['label'], $s['value']], $stats));
                    break;
            }
            return ExitCode::OK;
        } catch (ValidationException $e) {
            if ($json) {
                $output->writeln(self::json(['error' => 'validation_failed', 'details' => $e->errors()]));
            } else {
                $io->error('Validation failed.');
                foreach ($e->errors() as $field => $msgs) {
                    $io->writeln('  <comment>' . $field . '</comment>: ' . implode(' ', $msgs));
                }
            }
            return ExitCode::DATAERR;
        } catch (NotFoundException $e) {
            $json ? $output->writeln(self::json(['error' => 'not_found', 'message' => $e->getMessage()])) : $io->error($e->getMessage());
            return ExitCode::DATAERR;
        }
    }

    private function overview(SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        $rows = $this->service->overview(false);
        if ($json) {
            $output->writeln(self::json(array_map(static fn($r) => [
                'dataset' => $r['dataset']->code, 'connection' => $r['dataset']->connection, 'engine' => $r['dataset']->engineLabel(),
                'table' => $r['dataset']->table, 'ok' => $r['ok'], 'count' => $r['count'], 'error' => $r['error'],
            ], $rows)));
            return ExitCode::OK;
        }
        $io->table(['Dataset', 'Connection', 'Engine', 'Table / collection', 'Rows', 'Status'], array_map(static fn($r) => [
            $r['dataset']->code, $r['dataset']->connection, $r['dataset']->engineLabel(), $r['dataset']->table,
            $r['count'] ?? '—', $r['ok'] ? '<info>OK</info>' : '<error>' . mb_strimwidth((string) $r['error'], 0, 70, '…') . '</error>',
        ], $rows));
        $io->writeln('Usage: ./yii multidb:crud <dataset> list|show|create|update|delete|stats [id] [--set field=value …]');
        return ExitCode::OK;
    }

    private function printRow(SymfonyStyle $io, OutputInterface $output, string $title, array $row, bool $json): void
    {
        if ($json) {
            $output->writeln(self::json($row));
            return;
        }
        $io->title($title);
        $cell = static fn(mixed $v): string => match (true) {
            is_array($v) => implode(', ', array_map('strval', $v)),
            $v === true => 'true',
            $v === false => 'false',
            default => is_scalar($v) ? (string) $v : '',
        };
        $io->table(['Field', 'Value'], array_map(static fn($k, $v) => [(string) $k, $cell($v)], array_keys($row), $row));
    }

    /** @param array<array-key, mixed> $pairs "field=value" strings @return array<string,string> */
    private static function pairs(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $p) {
            [$k, $v] = array_pad(explode('=', (string) $p, 2), 2, '');
            if (trim($k) !== '') {
                $out[trim($k)] = $v;
            }
        }
        return $out;
    }

    private static function json(mixed $v): string
    {
        return (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
