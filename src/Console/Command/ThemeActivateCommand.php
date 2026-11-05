<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Appearance\ThemeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * theme:activate <code> [--tenant=<uuid>]
 */
#[AsCommand(name: 'theme:activate', description: 'Activate a theme globally or for one tenant')]
final class ThemeActivateCommand extends Command
{
    public function __construct(private readonly ThemeService $themes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('code', InputArgument::REQUIRED, 'Theme code (theme:list)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant id (uuid) for a tenant-only theme');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = $input->getOption('tenant');
        try {
            $this->themes->activate(null, (string) $input->getArgument('code'), $tenant ? 'tenant' : 'global', $tenant ? (string) $tenant : null);
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        $output->writeln('<info>Theme activated' . ($tenant ? " for tenant $tenant" : ' globally') . '.</info>');
        return self::SUCCESS;
    }
}
