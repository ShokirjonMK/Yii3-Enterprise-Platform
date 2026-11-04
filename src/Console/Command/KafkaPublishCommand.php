<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Messaging\Kafka\KafkaPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Yiisoft\Yii\Console\ExitCode;

/**
 *   ./yii kafka:publish platform.notifications.inbound '{"user":"admin","message":"Hello"}'
 *   ./yii kafka:publish orders.created '[{"id":1},{"id":2}]' --batch --key=order-1
 *
 * Delivery is confirmed by the broker before the command returns (exit code 0). Equivalent of
 * `kafka-publish/send|batch` from muxtorov98/yii2-kafka.
 *
 * Author: ShokirjonMK
 */
#[AsCommand(name: 'kafka:publish', description: 'Publish a JSON message (or a JSON array with --batch) to a Kafka topic')]
final class KafkaPublishCommand extends Command
{
    public function __construct(
        private readonly KafkaPublisher $publisher,
        private readonly KafkaConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('topic', InputArgument::REQUIRED, 'Topic name (prefix is added automatically)')
            ->addArgument('json', InputArgument::REQUIRED, 'JSON object; with --batch a JSON array of messages')
            ->addOption('batch', 'b', InputOption::VALUE_NONE, 'Publish every element of the JSON array as its own message')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Message key (partitioning); default: event id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->enabled) {
            $output->writeln('<error>Kafka is disabled (KAFKA_ENABLED=false).</error>');
            return ExitCode::UNAVAILABLE;
        }
        $topic = (string) $input->getArgument('topic');
        $json = (string) $input->getArgument('json');
        $key = $input->getOption('key');
        try {
            $ids = $input->getOption('batch')
                ? $this->publisher->batch($topic, KafkaPublisher::decodeList($json), $key === null ? null : (string) $key)
                : [$this->publisher->send($topic, $json, $key === null ? null : (string) $key)];
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return ExitCode::DATAERR;
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return ExitCode::UNAVAILABLE;
        }
        $output->writeln(sprintf('<info>%d message(s) confirmed by Kafka → %s</info>', count($ids), $this->config->topic($topic)));
        foreach ($ids as $id) {
            $output->writeln("  event_id=$id");
        }
        return ExitCode::OK;
    }
}
