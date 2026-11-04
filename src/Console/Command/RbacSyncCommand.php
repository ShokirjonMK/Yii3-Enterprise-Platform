<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Access\RbacSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'rbac:sync', description: 'Apply RBAC catalog files (config/common/permissions/*.php) to storage')]
final class RbacSyncCommand extends Command
{
    public function __construct(private readonly RbacSynchronizer $sync)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->sync->sync();
        foreach ($stats as $k => $v) {
            $output->writeln(sprintf('%-22s %d', $k, $v));
        }
        return ExitCode::OK;
    }
}
