<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Application\SessionRegistry;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Identity\Mfa\MfaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Break-glass 2FA reset from the server console — for the case the panel cannot help: the only superadmin lost
 * the phone/security key AND the recovery codes. Removes every factor, the recovery codes and remembered devices,
 * ends the user's sessions; at the next sign-in the role policy forces a new enrolment (gate `mfa_setup`).
 *
 * Shell access to the application server is the authorisation; the action is audited (severity critical,
 * `user.mfa_reset.console`) with the reason. Interactive confirmation, or --force in scripts.
 *
 *   ./yii user:mfa-reset admin --reason="lost phone, ticket #123"
 *
 * Author: ShokirjonMK
 */
#[AsCommand(name: 'user:mfa-reset', description: 'Break-glass: remove all 2FA methods of a user (lost device + lost recovery codes)')]
final class UserMfaResetCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MfaManager $mfa,
        private readonly SessionRegistry $sessions,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::REQUIRED, 'Username or e-mail')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why (stored in the audit log)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->users->findByLogin((string) $input->getArgument('login'));
        if ($user === null) {
            $io->error('User not found.');
            return ExitCode::DATAERR;
        }
        $reason = trim((string) $input->getOption('reason'));
        if ($reason === '') {
            $io->error('--reason is required (it is stored in the audit log).');
            return ExitCode::USAGE;
        }
        $methods = array_column($this->mfa->enrolled($user), 'type');
        $io->writeln(sprintf(' User <info>%s</info> (%s), 2FA methods: %s', $user->username, $user->id, $methods === [] ? 'none' : implode(', ', $methods)));
        if (!$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('Non-interactive mode: pass --force to confirm.');
                return ExitCode::USAGE;
            }
            if (!$io->confirm('Remove ALL two-factor methods, recovery codes and remembered devices of this user and end the sessions?', false)) {
                $io->writeln(' Cancelled.');
                return ExitCode::OK;
            }
        }
        $this->mfa->resetAll($user->id);
        $ended = $this->sessions->revokeAllForUser($user->id, 'mfa_reset_console');
        $this->audit->record('user.mfa_reset.console', 'user', $user->id, ['methods' => $methods], ['methods' => []], [
            'reason' => mb_substr($reason, 0, 500),
            'sessions_ended' => $ended,
            'os_user' => get_current_user(),
            'host' => gethostname(),
        ], category: 'security', severity: 'critical', actorName: 'console');
        $io->success(sprintf('2FA reset for %s (%d session(s) ended). A new method must be set up at the next sign-in.', $user->username, $ended));
        return ExitCode::OK;
    }
}
