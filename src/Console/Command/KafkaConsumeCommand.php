<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Messaging\Kafka\KafkaConsumerRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'kafka:consume', description: 'Run a Kafka consumer group (config/common/events.php)')]
final class KafkaConsumeCommand extends Command
{
    public function __construct(
        private readonly KafkaConsumerRunner $runner,
        private readonly KafkaConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('group', null, InputOption::VALUE_REQUIRED, 'Consumer group', 'platform-default')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Stop after N messages')
            ->addOption('idle-exit', null, InputOption::VALUE_REQUIRED, 'Exit after N idle seconds (tests)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->enabled) {
            $output->writeln('<comment>Kafka disabled (KAFKA_ENABLED=false); consumer idles.</comment>');
            while (true) {
                sleep(60);
            }
        }
        $group = (string) $input->getOption('group');
        if ($group === 'all') {
            return $this->supervise(array_keys($this->runner->groups()), $output);
        }
        $max = $input->getOption('max');
        $n = $this->runner->run($group, $max === null ? null : (int) $max, (int) $input->getOption('idle-exit'));
        $output->writeln("Consumed $n message(s).");
        return ExitCode::OK;
    }

    /**
     * `--group=all`: one forked child process per consumer group (config/common/events*.php), restarted
     * with backoff when it exits; SIGTERM is forwarded for graceful shutdown. Intended for single-host
     * deployments (Docker Compose); Kubernetes runs one Deployment per group instead.
     *
     * @param list<string> $groups
     */
    private function supervise(array $groups, OutputInterface $output): int
    {
        if (!function_exists('pcntl_fork')) {
            $output->writeln('<error>--group=all requires ext-pcntl.</error>');
            return ExitCode::UNAVAILABLE;
        }
        $children = [];
        $stop = false;
        pcntl_async_signals(true);
        $forward = function () use (&$children, &$stop): void {
            $stop = true;
            foreach (array_keys($children) as $pid) {
                posix_kill($pid, SIGTERM);
            }
        };
        pcntl_signal(SIGTERM, $forward);
        pcntl_signal(SIGINT, $forward);

        $spawn = function (string $group) use (&$children, $output): void {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // child: fresh process state for the consumer loop
                pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGINT, SIG_DFL);
                exit($this->runner->run($group) >= 0 ? 0 : 1);
            }
            $children[$pid] = $group;
            $output->writeln("started consumer group $group (pid $pid)");
        };
        foreach ($groups as $g) {
            $spawn($g);
        }
        while ($children !== []) {
            $pid = pcntl_wait($status);
            if ($pid <= 0) {
                continue;
            }
            $group = $children[$pid] ?? null;
            unset($children[$pid]);
            if (!$stop && $group !== null) {
                $output->writeln("consumer group $group exited (status $status); restarting in 5 s");
                sleep(5);
                $spawn($group);
            }
        }
        return ExitCode::OK;
    }
}
