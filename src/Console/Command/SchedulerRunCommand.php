<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Scheduler\Scheduler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'scheduler:run', description: 'Enqueue due scheduled jobs (once, or as a leader-elected daemon)')]
final class SchedulerRunCommand extends Command
{
    private bool $stop = false;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('daemon', null, InputOption::VALUE_NONE, 'Run continuously (tick every 15 s); only the leader enqueues');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('daemon')) {
            $n = $this->scheduler->tick();
            $output->writeln("Enqueued $n job(s).");
            return ExitCode::OK;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->stop = true);
            pcntl_signal(SIGINT, fn() => $this->stop = true);
        }
        $leader = false;
        while (!$this->stop) {
            try {
                if (!$leader) {
                    $leader = $this->scheduler->acquireLeadership();
                    if ($leader) {
                        $this->logger->notice('Scheduler {h}:{p} became leader', ['h' => gethostname(), 'p' => getmypid()]);
                    }
                }
                if ($leader) {
                    $this->scheduler->tick();
                }
            } catch (Throwable $e) {
                $this->logger->error('Scheduler tick failed: {e}', ['e' => $e->getMessage()]);
                $leader = false; // connection may be lost → lock released; re-elect
            }
            for ($i = 0; $i < 150 && !$this->stop; $i++) {
                usleep(100_000);
            }
        }
        return ExitCode::OK;
    }
}
