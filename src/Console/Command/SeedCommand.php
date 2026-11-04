<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Setup\PlatformSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'platform:seed', description: 'Insert missing reference data (idempotent)')]
final class SeedCommand extends Command
{
    public function __construct(private readonly PlatformSeeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->seeder->seed() as $k => $v) {
            $output->writeln(sprintf('%-12s +%d', $k, $v));
        }
        return ExitCode::OK;
    }
}
