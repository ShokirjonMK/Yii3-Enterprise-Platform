<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Messaging\Kafka\KafkaConsumerRunner;
use Predis\ClientInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'kafka:consume', description: 'Run a Kafka consumer group (config/common/events.php + #[KafkaChannel] handlers)')]
final class KafkaConsumeCommand extends Command
{
    public function __construct(
        private readonly KafkaConsumerRunner $runner,
        private readonly KafkaConfig $config,
        private readonly ConnectionInterface $db,
        private readonly ClientInterface $redis,
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
        return $this->runner->configChanged() ? KafkaConsumerRunner::EXIT_CONFIG_CHANGED : ExitCode::OK;
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
                $this->runner->run($group);
                exit($this->runner->configChanged() ? KafkaConsumerRunner::EXIT_CONFIG_CHANGED : 0);
            }
            $children[$pid] = $group;
            $output->writeln("started consumer group $group (pid $pid)");
        };
        // The supervisor read settings (DB/Redis) while building the configuration. A socket inherited across
        // fork() would be shared by all children (one child's exit/terminate breaks the others), so close
        // both here; every child opens its own connections lazily. The supervisor itself needs none.
        $this->db->close();
        try {
            $this->redis->disconnect();
        } catch (\Throwable) {
        }
        foreach ($groups as $g) {
            $spawn($g);
        }
        $restart = false;
        while ($children !== []) {
            $pid = pcntl_wait($status);
            if ($pid <= 0) {
                continue;
            }
            $group = $children[$pid] ?? null;
            unset($children[$pid]);
            if (!$stop && pcntl_wifexited($status) && pcntl_wexitstatus($status) === KafkaConsumerRunner::EXIT_CONFIG_CHANGED) {
                // children are forked from this process and would inherit the old configuration:
                // stop everything and let the container/pod restart policy start a fresh supervisor
                $output->writeln('Kafka settings changed; restarting all consumer groups');
                $forward();
                $restart = true;
                continue;
            }
            if (!$stop && $group !== null) {
                $output->writeln("consumer group $group exited (status $status); restarting in 5 s");
                sleep(5);
                $spawn($group);
            }
        }
        return $restart ? KafkaConsumerRunner::EXIT_CONFIG_CHANGED : ExitCode::OK;
    }
}
