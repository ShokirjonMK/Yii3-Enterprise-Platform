<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Messaging\Kafka\KafkaConsumerRunner;
use App\Platform\Messaging\Kafka\KafkaHandlerDiscovery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Lists consumer groups with their topics and handlers (config files + #[KafkaChannel] discovery).
 *
 * Author: ShokirjonMK
 */
#[AsCommand(name: 'kafka:handlers', description: 'List Kafka consumer groups, topics and discovered handlers')]
final class KafkaHandlersCommand extends Command
{
    public function __construct(
        private readonly KafkaConsumerRunner $runner,
        private readonly KafkaHandlerDiscovery $discovery,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['Group', 'Topics', 'Event handlers', 'Message handlers (raw JSON)']);
        foreach ($this->runner->groups() as $group => $def) {
            $events = [];
            foreach ($def['handlers'] as $type => $class) {
                $events[] = "$type → " . self::short($class);
            }
            $raw = [];
            foreach ($def['raw'] ?? [] as $topic => $classes) {
                foreach ($classes as $class) {
                    $raw[] = "$topic → " . self::short($class);
                }
            }
            $table->addRow([$group, implode("\n", $group === 'platform-retry' ? array_keys(KafkaConsumerRunner::RETRY_TOPICS) : $def['topics']), implode("\n", $events) ?: '—', implode("\n", $raw) ?: '—']);
        }
        $table->render();
        $output->writeln(sprintf('%d handler(s) discovered via #[KafkaChannel].', count($this->discovery->handlers())));
        return ExitCode::OK;
    }

    private static function short(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}
