<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Queue\Worker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'queue:work', description: 'Run a queue worker (graceful SIGTERM shutdown)')]
final class QueueWorkCommand extends Command
{
    public function __construct(private readonly Worker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Comma-separated queues in priority order', 'default')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep when idle', '1')
            ->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Exit after N jobs (process recycling)', '1000')
            ->addOption('memory', null, InputOption::VALUE_REQUIRED, 'Exit when memory exceeds N MB', '256')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process available jobs and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queues = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('queue')))));
        $n = $this->worker->run(
            $queues,
            (float) $input->getOption('sleep'),
            (int) $input->getOption('max-jobs'),
            (int) $input->getOption('memory'),
            (bool) $input->getOption('once'),
        );
        $output->writeln("Processed $n job(s).");
        return ExitCode::OK;
    }
}
