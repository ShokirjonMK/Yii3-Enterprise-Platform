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
 * theme:make <code> [--from=default] [--name="My brand"] — scaffold themes/<code>/ to edit.
 */
#[AsCommand(name: 'theme:make', description: 'Create a new theme folder (themes/<code>) from an existing theme')]
final class ThemeMakeCommand extends Command
{
    public function __construct(private readonly ThemeService $themes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('code', InputArgument::REQUIRED, 'New theme code: lowercase letters, digits, "-"')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Copy from this theme', 'default')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $dir = $this->themes->scaffold((string) $input->getArgument('code'), (string) $input->getOption('from'), $input->getOption('name') ? (string) $input->getOption('name') : null);
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        $output->writeln("<info>Created $dir</info>");
        $output->writeln('Edit theme.css (colours) and theme.json, then: php yii theme:activate ' . $input->getArgument('code'));
        return self::SUCCESS;
    }
}
