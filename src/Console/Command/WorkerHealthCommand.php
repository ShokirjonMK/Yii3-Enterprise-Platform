<?php

declare(strict_types=1);

namespace App\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Container liveness probe for worker processes: succeeds when a worker of this host
 * heartbeated within the last 90 seconds.
 */
#[AsCommand(name: 'worker:health', description: 'Exit 0 when a worker on this host is alive (liveness probe)')]
final class WorkerHealthCommand extends Command
{
    public function __construct(private readonly ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Worker type: queue|kafka', 'queue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $alive = (int) $this->db->createCommand(
            "SELECT count(*) FROM workers WHERE hostname = :h AND type = :t AND status NOT IN ('stopped','lost') AND heartbeat_at > now() - interval '90 seconds'",
            [':h' => gethostname(), ':t' => $input->getOption('type')],
        )->queryScalar();
        $output->writeln($alive > 0 ? 'alive' : 'no live worker');
        return $alive > 0 ? ExitCode::OK : ExitCode::UNAVAILABLE;
    }
}
