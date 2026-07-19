<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\SshCompose;

use Vortos\Deploy\Canary\CanaryAnalysisRequest;
use Vortos\Deploy\Canary\CanaryDecision;
use Vortos\Deploy\Canary\CanaryGate;
use Vortos\Deploy\Canary\CanaryMetricSpec;
use Vortos\Deploy\Canary\CanarySloRef;
use Vortos\Deploy\Canary\CanaryWindow;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Cutover\CutoverCoordinator;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Exception\ContractInSameDeployException;
use Vortos\Deploy\Exception\DestructiveMigrationUnannotatedException;
use Vortos\Deploy\Exception\CutoverRevertedException;
use Vortos\Deploy\Exception\DeployAbortedException;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshTransportInterface;
use Vortos\Deploy\Gate\GateBudget;
use Vortos\Deploy\Gate\ReadinessGateInterface;
use Vortos\Deploy\Gate\SmokeRunnerInterface;
use Vortos\Deploy\Gate\SmokeSpec;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\Deploy\State\StepOutcome;
use Vortos\Deploy\State\StepStatus;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Worker\DrainBudget;
use Vortos\Deploy\Worker\WorkerRolloutCoordinator;
use Vortos\Deploy\Worker\WorkerRolloutPlan;
use Vortos\Docker\Worker\WorkerProcessRegistry;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;
use Vortos\Migration\Service\MigrationLockSafetyEnforcer;

final class StepExecutor
{
    /** @var list<string> */
    private array $tempFiles = [];

    public function __construct(
        private readonly DeployStateStoreInterface $stateStore,
        private readonly ContainerRegistryInterface $registry,
        private readonly ReadinessGateInterface $readinessGate,
        private readonly SmokeRunnerInterface $smokeRunner,
        private readonly ComposeProjectFactory $composeFactory,
        private readonly CommandRunnerInterface $localRunner,
        private readonly ?SshTransportInterface $sshTransport = null,
        private readonly ?MigrationLockSafetyEnforcer $lockEnforcer = null,
        private readonly ?MigrationPhaseReaderInterface $phaseReader = null,
        private readonly ?CutoverCoordinator $cutoverCoordinator = null,
        private readonly ?WorkerRolloutCoordinator $workerCoordinator = null,
        private readonly ?WorkerProcessRegistry $workerRegistry = null,
        private readonly ?CanaryGate $canaryGate = null,
        /**
         * The public TLS domain the edge serves (e.g. api.example.com). Threaded into the cutover's
         * DesiredRoute so the pushed Caddy config carries the host matcher + tls.automation for it and
         * a /load PRESERVES the domain's certificate instead of clobbering it to Caddy's internal
         * default (GAP-D). Empty/null builds an internal / no-TLS edge.
         */
        private readonly ?string $edgeDomain = null,
        /**
         * Converges the edge service (compose) on the target as part of the deploy, idempotently
         * (recreate only on change). Null in local/dev and pull installs — the reconcile step then
         * reports a skip. Push-mode only, since it delivers + ups compose on the box over SSH.
         */
        private readonly ?EdgeServiceReconciler $edgeReconciler = null,
    ) {}

    public function execute(DeployPlan $plan, DeployRun $run, ImageReference $image): void
    {
        try {
            $stepIndex = 0;

            foreach ($plan->phases as $phase) {
                foreach ($phase->steps as $step) {
                    if ($run->isStepCompleted($stepIndex)) {
                        $stepIndex++;
                        continue;
                    }

                    $this->executeStep($step, $stepIndex, $run, $image);
                    $stepIndex++;
                }
            }
        } finally {
            $this->cleanupTempFiles();
        }
    }

    private function executeStep(DeployStep $step, int $stepIndex, DeployRun $run, ImageReference $image): void
    {
        $result = match ($step->action) {
            StepAction::PullImage => $this->handlePullImage($step, $image),
            StepAction::StartContainer => $this->handleStartContainer($step, $image),
            StepAction::StopContainer => $this->handleStopContainer($step),
            StepAction::CheckHealth => $this->handleCheckHealth($step),
            StepAction::RunSmoke => $this->handleRunSmoke($step),
            StepAction::SwitchUpstream => $this->handleSwitchUpstream($step),
            StepAction::UpdateState => $this->handleUpdateState($step),
            StepAction::RunMigrations => $this->handleRunMigrations($step),
            StepAction::DrainWorker => $this->handleDrainWorker($step, $image),
            StepAction::StartWorker => $this->handleStartWorker($step, $image),
            StepAction::WeightedRoute => $this->handleWeightedRoute($step, $image),
            StepAction::ReconcileEdge => $this->handleReconcileEdge($step, $image),
            StepAction::WaitDrain, StepAction::Noop => 'no-op',
        };

        $outcome = new StepOutcome(
            stepIndex: $stepIndex,
            action: $step->action,
            status: StepStatus::Success,
            result: $result,
        );

        $this->stateStore->checkpoint($run->runId, $stepIndex, $outcome);
        $run->addOutcome($outcome);
    }

    private function handleWeightedRoute(DeployStep $step, ImageReference $image): string
    {
        if ($this->cutoverCoordinator === null) {
            return sprintf('cutover coordinator not wired: weighted route %d%% skipped', $step->params['weight'] ?? 0);
        }

        $weight = (int) ($step->params['weight'] ?? 0);
        $colorValue = (string) ($step->params['color'] ?? '');
        $color = ActiveColor::from($colorValue);
        $endpoint = $this->composeFactory->endpointFor($color);
        $previousEndpoint = $this->composeFactory->endpointFor($color->opposite());

        $desired = new DesiredRoute(
            env: (string) ($step->params['env'] ?? 'production'),
            activeColor: $color,
            upstream: $endpoint,
            drainDeadlineSeconds: (int) ($step->params['drain_deadline_seconds'] ?? 30),
            weight: $weight,
        );

        try {
            $this->cutoverCoordinator->cutover(
                desired: $desired,
                imageDigest: (string) ($step->params['image_digest'] ?? ''),
                buildId: (string) ($step->params['build_id'] ?? ''),
                planHash: (string) ($step->params['plan_hash'] ?? ''),
                previousEndpoint: $previousEndpoint,
            );
        } catch (CutoverRevertedException $e) {
            throw DeployAbortedException::healthGateFailed($color->value, 0);
        }

        return sprintf('weighted route %d%% → %s', $weight, $color->value);
    }

    private function handlePullImage(DeployStep $step, ImageReference $image): string
    {
        $this->assertDigestPinned($image);

        // In the push model the image must be pulled ON THE VPS, not on the runner. Pulling
        // locally here would put the image on the wrong host (and, historically, under a
        // bogus repository). Over SSH we docker-pull the fully-qualified repo@digest on the
        // target; without a transport (local/dev) we fall back to the local registry.
        if ($this->sshTransport !== null) {
            $this->sshTransport
                ->run(new RemoteCommand(['docker', 'pull', $image->toString()]))
                ->throwOnFailure('remote docker pull');

            return sprintf('pulled %s on target', $image->toString());
        }

        $this->registry->pull($image);

        return sprintf('pulled %s', $image->toString());
    }

    private function handleStartContainer(DeployStep $step, ImageReference $image): string
    {
        $this->assertDigestPinned($image);

        $colorValue = (string) ($step->params['color'] ?? '');
        $color = ActiveColor::from($colorValue);
        $compose = $this->composeFactory->create($color, $image);
        $yaml = json_encode($compose->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if ($this->sshTransport !== null) {
            $remotePath = sprintf('/tmp/vortos-deploy-%s.yml', $color->value);
            $this->sshTransport->copy($this->writeTemp($yaml), $remotePath, '0600');

            $this->sshTransport->run(new RemoteCommand([
                'docker', 'compose', '-f', $remotePath, '-p', $compose->projectName, 'up', '-d',
            ]));
        } else {
            $tmpFile = $this->writeTemp($yaml);
            $this->localRunner->run([
                'docker', 'compose', '-f', $tmpFile, '-p', $compose->projectName, 'up', '-d',
            ]);
        }

        return sprintf('started %s', $compose->projectName);
    }

    private function handleStopContainer(DeployStep $step): string
    {
        $colorValue = (string) ($step->params['color'] ?? '');
        $color = ActiveColor::from($colorValue);
        $projectName = sprintf('vortos-app-%s', $color->value);

        $argv = ['docker', 'compose', '-p', $projectName, 'down'];
        if ($this->sshTransport !== null) {
            $this->sshTransport->run(new RemoteCommand($argv));
        } else {
            $this->localRunner->run($argv);
        }

        return sprintf('stopped %s', $projectName);
    }

    private function handleCheckHealth(DeployStep $step): string
    {
        // Canary SLO gate — triggered when the step carries a 'weight' param
        if (isset($step->params['weight']) && $this->canaryGate !== null) {
            return $this->handleCanarySloGate($step);
        }

        $colorValue = (string) ($step->params['color'] ?? '');
        $color = ActiveColor::from($colorValue);
        $endpoint = $this->composeFactory->endpointFor($color);
        $timeout = (float) ($step->params['timeout_seconds'] ?? 60);
        $stabilization = (float) ($step->params['stabilization_seconds'] ?? 0);

        // withStabilization() gives the color up to $timeout to first report ready, then requires it to
        // hold ready continuously for ~$stabilization before the gate passes — so traffic never cuts
        // over to a color that is only momentarily ready and still flapping under warmup. forTimeout()'s
        // deadline-derived attempt ceiling still applies (a generous timeout is never clamped to ~60s).
        $budget = GateBudget::withStabilization($timeout, $stabilization);
        $result = $this->readinessGate->awaitReady($color, $endpoint, $budget);

        if (!$result->passed) {
            throw DeployAbortedException::healthGateFailed($color->value, $result->attempts);
        }

        return sprintf(
            'healthy after %d attempts (%.1fs, %ds stabilization)',
            $result->attempts,
            $result->elapsed,
            (int) $stabilization,
        );
    }

    private function handleCanarySloGate(DeployStep $step): string
    {
        assert($this->canaryGate !== null);

        $weight = (int) ($step->params['weight'] ?? 0);
        $stagedColor = ActiveColor::from((string) ($step->params['color'] ?? 'blue'));
        $stableColor = $stagedColor->opposite();

        $window = CanaryWindow::default();
        $specs = [
            CanaryMetricSpec::errorRate(
                new CanarySloRef('error-rate', 'http_request_errors_total', 0.99),
            ),
        ];

        $request = new CanaryAnalysisRequest(
            env: (string) ($step->params['env'] ?? 'production'),
            staged: $stagedColor,
            stable: $stableColor,
            weight: $weight,
            specs: $specs,
            window: $window,
            buildId: (string) ($step->params['build_id'] ?? ''),
            at: new \DateTimeImmutable(),
        );

        $verdict = $this->canaryGate->gate($request);

        if ($verdict->isRollback()) {
            throw DeployAbortedException::healthGateFailed(
                sprintf('canary SLO breach at %d%%: %s', $weight, $verdict->reason),
                0,
            );
        }

        return sprintf('canary SLO gate passed at %d%%: %s', $weight, $verdict->reason);
    }

    private function handleRunSmoke(DeployStep $step): string
    {
        $colorValue = (string) ($step->params['color'] ?? '');
        $color = ActiveColor::from($colorValue);
        $endpoint = $this->composeFactory->endpointFor($color);

        $spec = new SmokeSpec();
        $result = $this->smokeRunner->run($color, $endpoint, $spec);

        if (!$result->passed) {
            $failedChecks = array_filter(
                $result->checks,
                static fn ($c) => !$c->passed,
            );
            $reasons = array_map(
                static fn ($c) => sprintf('%s: %s', $c->path, $c->reason),
                $failedChecks,
            );

            throw DeployAbortedException::smokeFailed($color->value, implode('; ', $reasons));
        }

        return 'smoke passed';
    }

    private function handleSwitchUpstream(DeployStep $step): string
    {
        if ($this->cutoverCoordinator === null) {
            return sprintf(
                'cutover coordinator not wired: upstream switch from %s to %s skipped',
                $step->params['from'] ?? 'none',
                $step->params['to'] ?? 'unknown',
            );
        }

        $toColor = ActiveColor::from((string) ($step->params['to'] ?? 'blue'));
        $fromColor = ActiveColor::from((string) ($step->params['from'] ?? 'none'));
        $endpoint = $this->composeFactory->endpointFor($toColor);
        $previousEndpoint = $this->composeFactory->endpointFor($fromColor);
        $drainDeadline = (int) ($step->params['drain_deadline_seconds'] ?? 30);

        $desired = new DesiredRoute(
            env: (string) ($step->params['env'] ?? 'production'),
            activeColor: $toColor,
            upstream: $endpoint,
            drainDeadlineSeconds: $drainDeadline,
            domain: ($this->edgeDomain !== null && $this->edgeDomain !== '') ? $this->edgeDomain : null,
        );

        try {
            $result = $this->cutoverCoordinator->cutover(
                desired: $desired,
                imageDigest: (string) ($step->params['image_digest'] ?? ''),
                buildId: (string) ($step->params['build_id'] ?? ''),
                planHash: (string) ($step->params['plan_hash'] ?? ''),
                previousEndpoint: $previousEndpoint,
            );
        } catch (CutoverRevertedException $e) {
            // The edge cutover verify failed and the coordinator reverted to the previous color.
            // Surface the REAL revert reason — not a bogus "health gate failed after 0 attempts",
            // which conflates a cutover/edge failure with the earlier readiness gate.
            throw DeployAbortedException::cutoverReverted($toColor->value, $e->getMessage());
        }

        return sprintf(
            'cutover to %s verified (drained=%d, forced=%d, %dms)',
            $toColor->value,
            $result->drainedConnections,
            $result->forciblyClosed,
            $result->durationMs,
        );
    }

    private function handleReconcileEdge(DeployStep $step, ImageReference $image): string
    {
        if ($this->edgeReconciler === null) {
            return 'edge reconcile skipped (no edge reconciler wired — local/dev or pull install)';
        }

        // A configured-but-broken base config or a failed compose up throws, aborting the deploy
        // BEFORE the cutover — the edge is converged first so the later /load has a service to hit.
        // The deployed image ref is threaded through as $VORTOS_APP_IMAGE for the edge-init service.
        $outcome = $this->edgeReconciler->reconcile($this->edgeDomain, $image->toString());

        return $outcome->detail();
    }

    private function handleUpdateState(DeployStep $step): string
    {
        return sprintf(
            'promoted color=%s digest=%s',
            $step->params['color'] ?? 'unknown',
            $step->params['image_digest'] ?? 'unknown',
        );
    }

    private function handleRunMigrations(DeployStep $step): string
    {
        // Defense-in-depth: re-assert no contract migrations in the applied delta
        if ($this->phaseReader !== null && isset($step->params['pending_ids']) && \is_string($step->params['pending_ids'])) {
            $pendingIds = array_filter(explode(',', $step->params['pending_ids']));

            if ($pendingIds !== []) {
                $phases = $this->phaseReader->phasesFor($pendingIds);
                $contractIds = [];
                $destructiveUnannotated = [];

                foreach ($phases as $id => $phase) {
                    // A destructive migration with no #[DeployPhase] classifies as Contract
                    // (fail-closed); surface it with the precise remediation rather than the
                    // generic contract message so the operator knows to annotate it.
                    if ($this->phaseReader->isDestructiveAndUnannotated($id)) {
                        $destructiveUnannotated[] = $id;
                        continue;
                    }

                    if ($phase === MigrationPhase::Contract) {
                        $contractIds[] = $id;
                    }
                }

                if ($destructiveUnannotated !== []) {
                    throw new DestructiveMigrationUnannotatedException($destructiveUnannotated);
                }

                if ($contractIds !== []) {
                    throw new ContractInSameDeployException($contractIds);
                }
            }
        }

        // Apply lock safety before running migrations
        if ($this->lockEnforcer !== null) {
            $this->lockEnforcer->enforce();
        }

        try {
            return sprintf(
                'migrations applied (fingerprint=%s, lock_timeout=%dms)',
                $step->params['fingerprint'] ?? 'none',
                $this->lockEnforcer?->lockTimeoutMs() ?? 0,
            );
        } finally {
            if ($this->lockEnforcer !== null) {
                $this->lockEnforcer->reset();
            }
        }
    }

    private function handleDrainWorker(DeployStep $step, ImageReference $image): string
    {
        if ($this->workerCoordinator === null || $this->workerRegistry === null) {
            return sprintf(
                'worker coordinator not wired: drain skipped (deadline=%ds)',
                $step->params['deadline_seconds'] ?? 30,
            );
        }

        $this->assertDigestPinned($image);

        if ($this->workerRegistry->isEmpty()) {
            return 'no workers registered — drain skipped';
        }

        $plan = WorkerRolloutPlan::fromRegistry($this->workerRegistry);
        $budget = new DrainBudget(
            deadlineSeconds: (int) ($step->params['deadline_seconds'] ?? $this->workerRegistry->maxDrainDeadline()),
        );

        $outcomes = $this->workerCoordinator->rollout($plan, $image, $budget);

        return WorkerRolloutCoordinator::summarize($outcomes);
    }

    private function handleStartWorker(DeployStep $step, ImageReference $image): string
    {
        $this->assertDigestPinned($image);

        return 'noop — workers launched during drain rollout';
    }

    private function assertDigestPinned(ImageReference $image): void
    {
        if (!$image->isDigestPinned()) {
            throw DeployAbortedException::digestNotPinned($image->toString());
        }
    }

    private function writeTemp(string $content): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'vortos-compose-');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temp file.');
        }

        chmod($tmpFile, 0600);
        file_put_contents($tmpFile, $content);
        $this->tempFiles[] = $tmpFile;

        return $tmpFile;
    }

    private function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $size = filesize($path);
            if ($size !== false && $size > 0) {
                @file_put_contents($path, str_repeat("\0", $size));
            }

            @unlink($path);
        }

        $this->tempFiles = [];
    }
}
