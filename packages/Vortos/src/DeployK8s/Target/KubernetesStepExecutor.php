<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Target;

use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Exception\DeployAbortedException;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\Deploy\State\StepOutcome;
use Vortos\Deploy\State\StepStatus;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Worker\DrainBudget;
use Vortos\Deploy\Worker\WorkerHandle;
use Vortos\DeployK8s\Api\KubeApiInterface;
use Vortos\DeployK8s\Edge\KubernetesEdgeRouter;
use Vortos\DeployK8s\Manifest\KubernetesManifestRenderer;
use Vortos\DeployK8s\Manifest\RenderContext;
use Vortos\DeployK8s\Worker\KubernetesWorkerController;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class KubernetesStepExecutor
{
    private const DEFAULT_NAMESPACE = 'default';
    private const HEALTH_CHECK_MAX_ATTEMPTS = 60;
    private const HEALTH_CHECK_INTERVAL_SECONDS = 5;
    private const MIGRATION_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly KubeApiInterface $kubeApi,
        private readonly DeployStateStoreInterface $stateStore,
        private readonly KubernetesManifestRenderer $renderer,
        private readonly ?WorkerProcessRegistry $workerRegistry = null,
    ) {}

    public function execute(DeployPlan $plan, DeployRun $run, ImageReference $image): void
    {
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
    }

    private function executeStep(DeployStep $step, int $stepIndex, DeployRun $run, ImageReference $image): void
    {
        $result = match ($step->action) {
            StepAction::PullImage => $this->handlePullImage($step, $image),
            StepAction::StartContainer => $this->handleStartContainer($step, $image),
            StepAction::CheckHealth => $this->handleCheckHealth($step),
            StepAction::RunSmoke => $this->handleRunSmoke($step),
            StepAction::SwitchUpstream => $this->handleSwitchUpstream($step),
            StepAction::WeightedRoute => $this->handleWeightedRoute($step),
            StepAction::RunMigrations => $this->handleRunMigrations($step, $image),
            StepAction::DrainWorker => $this->handleDrainWorker($step, $image),
            StepAction::StartWorker => $this->handleStartWorker($step, $image),
            StepAction::StopContainer => $this->handleStopContainer($step),
            StepAction::UpdateState => $this->handleUpdateState($step),
            StepAction::WaitDrain, StepAction::Noop => 'noop',
        };

        $outcome = new StepOutcome($stepIndex, $step->action, StepStatus::Success, (string) $result);
        $run->addOutcome($outcome);
        $this->stateStore->checkpoint($run->runId, $stepIndex, $outcome);
    }

    private function handlePullImage(DeployStep $step, ImageReference $image): string
    {
        if (!$image->isDigestPinned()) {
            throw DeployAbortedException::digestNotPinned($image->toString());
        }

        return 'digest-verified: ' . $image->digest;
    }

    private function handleStartContainer(DeployStep $step, ImageReference $image): string
    {
        if (!$image->isDigestPinned()) {
            throw DeployAbortedException::digestNotPinned($image->toString());
        }

        $color = (string) ($step->params['color'] ?? 'blue');
        $namespace = (string) ($step->params['namespace'] ?? self::DEFAULT_NAMESPACE);
        $appName = (string) ($step->params['app_name'] ?? 'app');

        $ctx = new RenderContext(
            namespace: $namespace,
            appName: $appName,
            imageReference: $image->toString(),
        );

        $workers = $this->workerRegistry ?? new WorkerProcessRegistry([]);
        $objects = $this->renderer->render($workers, $ctx, $color);

        $this->kubeApi->apply(...$objects);

        return sprintf('applied %d objects for color=%s', \count($objects), $color);
    }

    private function handleCheckHealth(DeployStep $step): string
    {
        $color = (string) ($step->params['color'] ?? 'blue');
        $namespace = (string) ($step->params['namespace'] ?? self::DEFAULT_NAMESPACE);
        $appName = (string) ($step->params['app_name'] ?? 'app');
        $deploymentName = $appName . '-' . $color;

        $maxAttempts = (int) ($step->params['max_attempts'] ?? self::HEALTH_CHECK_MAX_ATTEMPTS);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $status = $this->kubeApi->rolloutStatus('Deployment', $deploymentName, $namespace);

            if ($status->isComplete()) {
                return sprintf('healthy after %d checks', $attempt);
            }

            if ($attempt < $maxAttempts) {
                usleep(self::HEALTH_CHECK_INTERVAL_SECONDS * 1_000_000);
            }
        }

        throw DeployAbortedException::healthGateFailed($color, $maxAttempts);
    }

    private function handleRunSmoke(DeployStep $step): string
    {
        $color = (string) ($step->params['color'] ?? 'blue');
        $namespace = (string) ($step->params['namespace'] ?? self::DEFAULT_NAMESPACE);
        $appName = (string) ($step->params['app_name'] ?? 'app');
        $deploymentName = $appName . '-' . $color;

        $status = $this->kubeApi->rolloutStatus('Deployment', $deploymentName, $namespace);
        if (!$status->ready) {
            throw DeployAbortedException::smokeFailed($color, 'deployment not ready');
        }

        return 'smoke passed';
    }

    private function handleSwitchUpstream(DeployStep $step): string
    {
        $color = (string) ($step->params['color'] ?? 'blue');
        $namespace = (string) ($step->params['namespace'] ?? self::DEFAULT_NAMESPACE);
        $appName = (string) ($step->params['app_name'] ?? 'app');

        $router = new KubernetesEdgeRouter($this->kubeApi, $appName, $namespace);
        $activeColor = ActiveColor::from($color);

        $desired = new DesiredRoute(
            env: 'production',
            activeColor: $activeColor,
            upstream: new ColorEndpoint($appName . '-' . $color, 8080),
            drainDeadlineSeconds: (int) ($step->params['drain_deadline'] ?? 30),
        );

        $result = $router->cutover($desired);

        return sprintf('promoted color=%s, verified=%s', $color, $result->verifiedLiveUpstream ? 'yes' : 'no');
    }

    private function handleWeightedRoute(DeployStep $step): string
    {
        $color = (string) ($step->params['color'] ?? 'blue');
        $weight = (int) ($step->params['weight'] ?? 100);
        $namespace = (string) ($step->params['namespace'] ?? self::DEFAULT_NAMESPACE);
        $appName = (string) ($step->params['app_name'] ?? 'app');

        $router = new KubernetesEdgeRouter($this->kubeApi, $appName, $namespace);
        $activeColor = ActiveColor::from($color);

        $desired = new DesiredRoute(
            env: 'production',
            activeColor: $activeColor,
            upstream: new ColorEndpoint($appName . '-' . $color, 8080),
            drainDeadlineSeconds: 30,
            weight: $weight,
        );

        $router->cutover($desired);

        return sprintf('weighted route color=%s weight=%d%%', $color, $weight);
    }

    private function handleRunMigrations(DeployStep $step, ImageReference $image): string
    {
        if (!$image->isDigestPinned()) {
            throw DeployAbortedException::digestNotPinned($image->toString());
        }

        $namespace = (string) ($step->params['namespace'] ?? self::DEFAULT_NAMESPACE);
        $appName = (string) ($step->params['app_name'] ?? 'app');
        $command = (string) ($step->params['migration_command'] ?? 'php bin/console doctrine:migrations:migrate --no-interaction');

        $ctx = new RenderContext(
            namespace: $namespace,
            appName: $appName,
            imageReference: $image->toString(),
        );

        $job = $this->renderer->renderMigrationJob($ctx, explode(' ', $command));
        $this->kubeApi->createJob($job);

        $timeout = (int) ($step->params['timeout_seconds'] ?? self::MIGRATION_TIMEOUT_SECONDS);
        $status = $this->kubeApi->awaitJob($job->name, $namespace, $timeout);

        if ($status->failed) {
            throw new \RuntimeException(sprintf('Migration job failed: %s', $status->message));
        }

        return 'migration completed';
    }

    private function handleDrainWorker(DeployStep $step, ImageReference $image): string
    {
        $workerName = (string) ($step->params['worker_name'] ?? '');
        if ($workerName === '') {
            return 'no worker to drain';
        }

        $namespace = (string) ($step->params['namespace'] ?? self::DEFAULT_NAMESPACE);
        $drainDeadline = (int) ($step->params['drain_deadline'] ?? 25);
        $numprocs = (int) ($step->params['numprocs'] ?? 1);

        $controller = new KubernetesWorkerController($this->kubeApi, $namespace);
        $handle = new WorkerHandle($workerName, $numprocs, $drainDeadline);
        $budget = new DrainBudget(deadlineSeconds: $drainDeadline);

        $outcome = $controller->drain($handle, $budget);

        return sprintf('drained worker=%s graceful=%s', $workerName, $outcome->inFlightCompleted ? 'yes' : 'no');
    }

    private function handleStartWorker(DeployStep $step, ImageReference $image): string
    {
        $workerName = (string) ($step->params['worker_name'] ?? '');
        if ($workerName === '') {
            return 'no worker to start';
        }

        $namespace = (string) ($step->params['namespace'] ?? self::DEFAULT_NAMESPACE);
        $numprocs = (int) ($step->params['numprocs'] ?? 1);
        $drainDeadline = (int) ($step->params['drain_deadline'] ?? 25);

        $controller = new KubernetesWorkerController($this->kubeApi, $namespace);
        $handle = new WorkerHandle($workerName, $numprocs, $drainDeadline);

        $controller->launch($handle, $image);

        return sprintf('launched worker=%s replicas=%d', $workerName, $numprocs);
    }

    private function handleStopContainer(DeployStep $step): string
    {
        $color = (string) ($step->params['color'] ?? '');
        $namespace = (string) ($step->params['namespace'] ?? self::DEFAULT_NAMESPACE);
        $appName = (string) ($step->params['app_name'] ?? 'app');

        if ($color !== '') {
            $deploymentName = $appName . '-' . $color;
            $this->kubeApi->scale('Deployment', $deploymentName, $namespace, 0);
            return sprintf('scaled %s to 0', $deploymentName);
        }

        return 'noop';
    }

    private function handleUpdateState(DeployStep $step): string
    {
        $color = (string) ($step->params['color'] ?? '');
        return sprintf('promoted color=%s', $color);
    }
}
