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
        $max = $input->getOption('max');
        $n = $this->runner->run((string) $input->getOption('group'), $max === null ? null : (int) $max, (int) $input->getOption('idle-exit'));
        $output->writeln("Consumed $n message(s).");
        return ExitCode::OK;
    }
}
