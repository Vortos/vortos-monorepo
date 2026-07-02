<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Vortos\Deploy\Audit\ActorIdentitySource;
use Vortos\Deploy\Exception\RollbackRefusedException;
use Vortos\Deploy\Exception\RollbackTargetNotFoundException;
use Vortos\Deploy\Runner\RollbackRunner;

/**
 * 'deploy:rollback' — enforces the Block 8 rollback invariant.
 *
 * Refuses unsafe targets (prints the invariant decision and recovery instructions),
 * succeeds on legal ones. For an irreversible prod rollback without '--yes', the
 * operator must type the env name.
 */
#[AsCommand(name: 'deploy:rollback', description: 'Roll back an environment to a legal previous build')]
final class RollbackCommand extends Command
{
    public function __construct(
        private readonly ?RollbackRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', 'production');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Explicit rollback target build id (default: previous known-good)');
        $this->addOption('yes', null, InputOption::VALUE_NONE, 'Skip interactive confirmation');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable outcome');
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor id recorded in the deploy audit trail');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runner === null) {
            $output->writeln('<error>deploy:rollback requires the migration + release stack. '
                . 'Install vortos/vortos-migration and vortos/vortos-release and wire their read '
                . 'models, then re-run.</error>');

            return Command::FAILURE;
        }

        $env = (string) $input->getOption('env');
        $to = $input->getOption('to');
        $to = is_string($to) ? $to : null;
        $yes = (bool) $input->getOption('yes');
        $json = (bool) $input->getOption('json');

        if ($this->isProd($env) && !$yes && !$this->confirmProd($env, $input, $output)) {
            $output->writeln('<comment>Aborted: confirmation token did not match.</comment>');

            return Command::FAILURE;
        }

        $actorOption = $input->getOption('actor');
        $actorId = is_string($actorOption) && $actorOption !== ''
            ? $actorOption
            : (string) (getenv('VORTOS_DEPLOY_ACTOR') ?: get_current_user());

        try {
            $outcome = $this->runner->rollback($env, $to, $actorId, ActorIdentitySource::Local);
        } catch (RollbackRefusedException $e) {
            // Refusal is first-class and explained — print the invariant decision.
            if ($json) {
                $output->writeln(json_encode([
                    'env' => $env,
                    'status' => 'refused',
                    'reason' => $e->reason()->value,
                    'offending_migrations' => $e->offendingMigrations(),
                    'explanation' => $e->getMessage(),
                ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln('<error>ROLLBACK REFUSED</error>');
                $output->writeln($e->getMessage());
            }

            return Command::FAILURE;
        } catch (RollbackTargetNotFoundException $e) {
            if ($json) {
                $output->writeln(json_encode(['env' => $env, 'status' => 'refused', 'error' => $e->getMessage()], \JSON_THROW_ON_ERROR));
            } else {
                $output->writeln(sprintf('<error>ROLLBACK REFUSED — %s</error>', $e->getMessage()));
            }

            return Command::FAILURE;
        }

        // A completed rollback is a success for this verb (unlike a deploy that had
        // to auto-roll-back, which is a failure). Reaching here means the guard
        // accepted the target and the driver executed it.
        if ($json) {
            $output->writeln($outcome->toJson());

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>ROLLED BACK — %s: %s</info>', $env, $outcome->rollbackReason ?? ''));

        return Command::SUCCESS;
    }

    private function confirmProd(string $env, InputInterface $input, OutputInterface $output): bool
    {
        $helper = new \Symfony\Component\Console\Helper\QuestionHelper();

        $question = new Question(sprintf(
            "<comment>You are about to roll back PRODUCTION (%s).</comment>\nType the environment name to confirm: ",
            $env,
        ));

        return $helper->ask($input, $output, $question) === $env;
    }

    private function isProd(string $env): bool
    {
        return in_array(strtolower($env), ['production', 'prod'], true);
    }
}
