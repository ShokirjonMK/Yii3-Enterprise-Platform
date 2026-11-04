<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Messaging\Kafka\KafkaConfigFactory;
use App\Platform\Messaging\OutboxRelay;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'outbox:relay', description: 'Publish transactional-outbox events to Kafka')]
final class OutboxRelayCommand extends Command
{
    private bool $stop = false;

    public function __construct(
        private readonly OutboxRelay $relay,
        private readonly LoggerInterface $logger,
        private readonly KafkaConfig $config,
        private readonly KafkaConfigFactory $configFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('daemon', null, InputOption::VALUE_NONE, 'Run continuously')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Batch size', '200')
            ->addOption('requeue-failed', null, InputOption::VALUE_NONE, 'Move failed events back to pending and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('requeue-failed')) {
            $output->writeln('Requeued ' . $this->relay->requeueFailed() . ' event(s).');
            return ExitCode::OK;
        }
        $batch = (int) $input->getOption('batch');
        if (!$input->getOption('daemon')) {
            $r = $this->relay->relayOnce($batch);
            $output->writeln(sprintf('published=%d failed=%d%s', $r['published'], $r['failed'], $r['skipped'] ? ' (messaging.kafka flag disabled)' : ''));
            return $r['failed'] > 0 ? ExitCode::TEMPFAIL : ExitCode::OK;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->stop = true);
            pcntl_signal(SIGINT, fn() => $this->stop = true);
        }
        $lastCheck = microtime(true);
        while (!$this->stop) {
            // Kafka settings changed in the admin panel → exit; the container/pod restart policy starts a fresh relay
            if (microtime(true) - $lastCheck > 5) {
                $lastCheck = microtime(true);
                if ($this->configFactory->fresh()->fingerprint() !== $this->config->fingerprint()) {
                    $this->logger->notice('Kafka settings changed; outbox relay restarts');
                    return 75;
                }
            }
            try {
                $r = $this->relay->relayOnce($batch);
                $idle = $r['published'] === 0;
            } catch (Throwable $e) {
                $this->logger->error('Outbox relay error: {e}', ['e' => $e->getMessage()]);
                $idle = true;
            }
            if ($idle) {
                usleep(500_000);
            }
        }
        return ExitCode::OK;
    }
}
