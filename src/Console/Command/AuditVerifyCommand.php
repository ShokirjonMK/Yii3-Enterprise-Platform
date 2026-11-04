<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Audit\AuditVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'audit:verify', description: 'Verify HMAC integrity of audit log records')]
final class AuditVerifyCommand extends Command
{
    public function __construct(private readonly AuditVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED)->addOption('to', null, InputOption::VALUE_REQUIRED)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, '', '100000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $r = $this->verifier->verify($input->getOption('from'), $input->getOption('to'), (int) $input->getOption('limit'));
        $output->writeln(sprintf('checked=%d invalid=%d', $r['checked'], count($r['invalid'])));
        foreach (array_slice($r['invalid'], 0, 50) as $id) {
            $output->writeln("  tampered record id=$id");
        }
        return $r['invalid'] === [] ? ExitCode::OK : ExitCode::DATAERR;
    }
}
