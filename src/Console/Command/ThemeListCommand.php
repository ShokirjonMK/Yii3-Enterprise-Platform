<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Appearance\ThemeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * theme:list — installed design themes and which one is active.
 *
 * Author: ShokirjonMK
 */
#[AsCommand(name: 'theme:list', description: 'List design themes (built-in and uploaded) and the active one')]
final class ThemeListCommand extends Command
{
    public function __construct(private readonly ThemeService $themes)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $s = $this->themes->settings();
        $table = new Table($output);
        $table->setHeaders(['Code', 'Name', 'Version', 'Source', 'Active']);
        foreach ($this->themes->registry()->all() as $code => $t) {
            $table->addRow([$code, $t['name'], $t['version'], $t['builtin'] ? 'built-in (themes/' . $code . ')' : 'uploaded', $s['theme'] === $code ? 'global' : '']);
        }
        $table->render();
        $output->writeln(sprintf('Users may choose their own theme: %s. Tenant overrides: %d.', $s['allow_user_choice'] ? 'yes' : 'no', count($s['tenants'])));
        return self::SUCCESS;
    }
}
