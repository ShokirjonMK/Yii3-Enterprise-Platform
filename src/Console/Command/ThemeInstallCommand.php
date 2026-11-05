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
 * theme:install <file.zip> [--replace] [--activate]
 */
#[AsCommand(name: 'theme:install', description: 'Install a theme pack (.zip with theme.json + theme.css)')]
final class ThemeInstallCommand extends Command
{
    public function __construct(private readonly ThemeService $themes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to the .zip archive')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Replace an uploaded theme with the same code')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activate it globally after installing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $m = $this->themes->installZip(null, (string) $input->getArgument('file'), (bool) $input->getOption('replace'));
            $output->writeln(sprintf('<info>Installed "%s" (%s) %s</info>', $m['name'], $m['code'], $m['version']));
            if ($input->getOption('activate')) {
                $this->themes->activate(null, $m['code']);
                $output->writeln('<info>Activated globally.</info>');
            }
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
