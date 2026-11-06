<?php

declare(strict_types=1);

namespace App\Platform\Gii\Console;

use App\Environment;
use App\Platform\Gii\EnterpriseGii;
use App\Platform\Gii\PlannedFile;
use App\Platform\Gii\Spec\GiiSpecException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

/**
 * ./yii gii:module <Name> --fields="title:string:required,…" [--generators=all|crud,api,…] [--apply] [--overwrite=path,…] [--diff]
 *
 * Dry run by default (nothing is written). Prints every file with its status:
 *   new        will be created          unchanged  identical file exists (skipped)
 *   skip       exists with other content and is protected (pass its path in --overwrite to replace it)
 *   overwrite  exists, differs and was listed in --overwrite
 */
#[AsCommand(name: 'gii:module', description: 'Enterprise Gii: generate a fully integrated business module (dry run unless --apply)')]
final class GiiModuleCommand extends Command
{
    public function __construct(private readonly EnterpriseGii $gii)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Module name, PascalCase plural (e.g. Contracts)')
            ->addOption('fields', 'f', InputOption::VALUE_REQUIRED, 'Field spec, e.g. "title:string:required:max=255,amount:decimal:min=0,status:enum(draft|active|closed),due_on:date,owner_id:user"')
            ->addOption('generators', 'g', InputOption::VALUE_REQUIRED, 'all | comma-separated: ' . implode(',', array_keys(EnterpriseGii::GENERATORS)), 'all')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the files (default: dry run)')
            ->addOption('overwrite', null, InputOption::VALUE_REQUIRED, 'Comma-separated relative paths that may be overwritten', '')
            ->addOption('diff', null, InputOption::VALUE_NONE, 'Print the unified diff of changed existing files')
            ->addOption('show', null, InputOption::VALUE_REQUIRED, 'Print the generated content of one relative path', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (Environment::isProd()) {
            $output->writeln('<error>Enterprise Gii is not available in production.</error>');
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $overwrite = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('overwrite')))));
        try {
            $plan = $this->gii->plan(
                (string) $input->getArgument('name'),
                (string) $input->getOption('fields'),
                (string) $input->getOption('generators'),
                $overwrite,
            );
        } catch (GiiSpecException $e) {
            $output->writeln('<error>Code not generated. Please fix the following errors:</error>');
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $msg) {
                    $output->writeln(sprintf(' - <fg=cyan>%s</>: %s', $key, $msg));
                }
            }
            return ExitCode::DATAERR;
        }

        $apply = (bool) $input->getOption('apply');
        $output->writeln(sprintf(
            '<info>Enterprise Gii</info> · module <comment>%s</comment> · generators: %s · %s',
            $plan->module,
            implode(', ', $plan->generators),
            $apply ? '<fg=red>APPLY</>' : 'dry run (use --apply to write)',
        ));
        $output->writeln('');
        $colors = [PlannedFile::NEW => 'green', PlannedFile::OVERWRITE => 'blue', PlannedFile::SKIP => 'yellow', PlannedFile::UNCHANGED => 'gray'];
        foreach ($plan->files as $f) {
            $output->writeln(sprintf('  <fg=%s>%-10s</> %-13s %s', $colors[$f->status], '[' . $f->status . ']', $f->generator, $f->path()));
            if ($input->getOption('diff') && $f->exists() && $f->status !== PlannedFile::UNCHANGED) {
                $output->writeln($f->diff(), OutputInterface::OUTPUT_RAW);
            }
        }
        $show = (string) $input->getOption('show');
        if ($show !== '') {
            $file = $plan->file($show);
            $output->writeln($file === null ? "<error>$show is not part of this generation.</error>" : $file->content(), OutputInterface::OUTPUT_RAW);
        }
        $c = $plan->counts();
        $output->writeln('');
        $output->writeln(sprintf(
            '%d files: %d new, %d overwrite, %d skip (protected), %d unchanged.',
            count($plan->files),
            $c['new'],
            $c['overwrite'],
            $c['skip'],
            $c['unchanged'],
        ));

        if (!$apply) {
            if ($c['skip'] > 0) {
                $output->writeln('<comment>Protected files are never overwritten; list them in --overwrite=path,… after reviewing --diff.</comment>');
            }
            return ExitCode::OK;
        }
        $this->gii->apply($plan);
        foreach ($plan->files as $f) {
            if ($f->error !== null) {
                $output->writeln(sprintf('<error>error</error> %s: %s', $f->path(), $f->error));
            }
        }
        if ($plan->hasErrors()) {
            return ExitCode::IOERR;
        }
        $written = $c['new'] + $c['overwrite'];
        $output->writeln("<info>Written: $written file(s).</info> Next: ./yii migrate:up && ./yii rbac:sync"
            . (in_array('event', $plan->generators, true) ? ' && ./yii kafka:provision' : '')
            . ' (or Admin → Developer tools → Enterprise Gii → Next steps)');
        return ExitCode::OK;
    }
}
