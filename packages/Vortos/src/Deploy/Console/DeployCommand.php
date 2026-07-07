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
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Runner\DeployOutcome;
use Vortos\Deploy\Runner\DeployOutcomeStatus;
use Vortos\Deploy\Runner\DeployRequest;
use Vortos\Deploy\Runner\DeployExecutionMode;
use Vortos\Deploy\Runner\DeployRunner;

/**
 * 'deploy' — runs the whole assembled loop behind the fail-closed doctor.
 *
 * Doctor runs first; a red gate refuses the deploy (non-zero, nothing mutated).
 * '--dry-run' rehearses (plan + preview, zero mutation). For an irreversible prod
 * deploy without '--yes', the operator must type the env name (fat-finger guard); CI
 * passes '--yes'.
 */
#[AsCommand(name: 'deploy', description: 'Deploy an environment through the fail-closed preflight loop')]
final class DeployCommand extends Command
{
    public function __construct(
        private readonly ?DeployRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', 'production');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Rehearse: doctor + plan + preview, ZERO mutation');
        $this->addOption('yes', null, InputOption::VALUE_NONE, 'Skip interactive confirmation (CI / non-interactive)');
        $this->addOption('resume', null, InputOption::VALUE_NONE, 'Resume an interrupted run');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable outcome');
        $this->addOption('image-digest', null, InputOption::VALUE_REQUIRED, 'Pin the deployed image to this sha256 digest (promote-by-digest)');
        $this->addOption('image-repository', null, InputOption::VALUE_REQUIRED, 'Fully-qualified image repository to deploy (overrides the recorded manifest, e.g. ghcr.io/acme/app)');
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor id recorded in the deploy audit trail');
        $this->addOption('auto-publish', null, InputOption::VALUE_NONE, 'Publish any un-published module migration stubs before the doctor gate (opt-in; live deploys only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runner === null) {
            $output->writeln('<error>deploy requires the migration + release stack. Install '
                . 'vortos/vortos-migration and vortos/vortos-release and wire their read models, '
                . 'then re-run.</error>');

            return Command::FAILURE;
        }

        $env = (string) $input->getOption('env');
        $dryRun = (bool) $input->getOption('dry-run');
        $yes = (bool) $input->getOption('yes');
        $resume = (bool) $input->getOption('resume');
        $json = (bool) $input->getOption('json');
        $imageDigest = $input->getOption('image-digest');
        $imageRepository = $input->getOption('image-repository');

        $mode = $dryRun ? DeployExecutionMode::DryRun : DeployExecutionMode::Live;

        if ($mode === DeployExecutionMode::Live && $this->isProd($env) && !$yes) {
            if (!$this->confirmProd($env, $input, $output)) {
                $output->writeln('<comment>Aborted: confirmation token did not match.</comment>');

                return Command::FAILURE;
            }
        }

        $actorOption = $input->getOption('actor');
        $actorId = \is_string($actorOption) && $actorOption !== ''
            ? $actorOption
            : (string) (getenv('VORTOS_DEPLOY_ACTOR') ?: get_current_user());

        $request = new DeployRequest(
            $env,
            $mode,
            assumeYes: $yes,
            resume: $resume,
            imageDigest: \is_string($imageDigest) ? $imageDigest : null,
            imageRepository: \is_string($imageRepository) ? $imageRepository : null,
            actorId: $actorId,
            actorIdentitySource: ActorIdentitySource::Local,
            autoPublishMigrations: (bool) $input->getOption('auto-publish'),
        );

        try {
            $outcome = $this->runner->run($request);
        } catch (\Throwable $e) {
            if ($json) {
                // R8-3: machine JSON on stdout, human reason on stderr — so a CI log shows *why*
                // without a manual non-JSON re-run.
                $output->writeln(json_encode(['env' => $env, 'status' => 'error', 'error' => $e->getMessage()], \JSON_THROW_ON_ERROR));
                $this->errorOutput($output)->writeln(sprintf('Deploy failed for %s: %s', $env, $e->getMessage()));
            } else {
                $output->writeln(sprintf('<error>Deploy failed: %s</error>', $e->getMessage()));
            }

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln($outcome->toJson());

            // R8-3: on a refused/rolled-back deploy, echo the failing gate(s) to stderr. The JSON on
            // stdout is untouched (CI parsers keep working); operators get the reason in the log.
            $this->emitFailureSummaryToStderr($outcome, $output);

            return $outcome->exitCode();
        }

        $this->renderHuman($outcome, $output);

        return $outcome->exitCode();
    }

    /**
     * R8-3: in --json mode, surface the failing gate(s) / rollback reason to stderr so CI logs are
     * self-explanatory. Never writes to stdout (that stream stays pure machine JSON).
     */
    private function emitFailureSummaryToStderr(DeployOutcome $outcome, OutputInterface $output): void
    {
        if ($outcome->status === DeployOutcomeStatus::Refused && $outcome->report !== null) {
            $stderr = $this->errorOutput($output);
            foreach ($outcome->report->failures() as $failure) {
                $stderr->writeln(sprintf('REFUSED: %s — %s', $failure->id, $failure->summary));
            }
            $stderr->writeln(sprintf(
                'Deploy refused for %s: %d failing check(s). Nothing was deployed.',
                $outcome->env,
                $outcome->report->countByStatus(PreflightStatus::Fail),
            ));

            return;
        }

        if ($outcome->status === DeployOutcomeStatus::RolledBack) {
            $this->errorOutput($output)->writeln(sprintf(
                'ROLLED BACK: %s — %s',
                $outcome->env,
                $outcome->rollbackReason ?? 'release failed',
            ));
        }
    }

    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
    }

    private function confirmProd(string $env, InputInterface $input, OutputInterface $output): bool
    {
        $helper = new \Symfony\Component\Console\Helper\QuestionHelper();

        $question = new Question(sprintf(
            "<comment>You are about to deploy to PRODUCTION (%s).</comment>\nType the environment name to confirm: ",
            $env,
        ));

        $answer = $helper->ask($input, $output, $question);

        return $answer === $env;
    }

    private function isProd(string $env): bool
    {
        return in_array(strtolower($env), ['production', 'prod'], true);
    }

    private function renderHuman(DeployOutcome $outcome, OutputInterface $output): void
    {
        if ($outcome->preview !== null) {
            $output->writeln($outcome->preview);
        }

        $output->writeln(match ($outcome->status) {
            DeployOutcomeStatus::Refused => sprintf('<error>REFUSED — doctor not clear for %s (%d failing checks). Nothing was deployed.</error>', $outcome->env, $outcome->report?->countByStatus(PreflightStatus::Fail) ?? 0),
            DeployOutcomeStatus::DryRun => sprintf('<info>DRY RUN — plan rehearsed for %s. Nothing was mutated.</info>', $outcome->env),
            DeployOutcomeStatus::Deployed => sprintf('<info>DEPLOYED — %s is live.</info>', $outcome->env),
            DeployOutcomeStatus::RolledBack => sprintf('<error>ROLLED BACK — %s: %s</error>', $outcome->env, $outcome->rollbackReason ?? ''),
        });

        if ($outcome->report !== null && !$outcome->report->isClear()) {
            foreach ($outcome->report->failures() as $failure) {
                $output->writeln(sprintf('  <error>[FAIL]</error> %s — %s', $failure->id, $failure->summary));
            }
        }
    }
}
