<?php

declare(strict_types=1);

namespace Vortos\Deploy\Target;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\Release\Manifest\BuildManifest;

interface DeployTargetInterface extends DriverInterface
{
    public function plan(DeployContext $context): DeployPlan;

    /**
     * Fail-closed pre-release check: assert the fully-pinned image is present and
     * pullable in its registry BEFORE any mutation. The build job is the only pusher;
     * the deploy path never pushes — so a missing/typo'd digest must surface here, not
     * as a broken pull on the target.
     *
     * @throws \Vortos\Deploy\Exception\ImageNotAvailableException when the image is absent
     *         or its live digest does not match the pinned digest.
     */
    public function assertImageAvailable(ImageReference $image): void;

    public function migrate(DeployPlan $plan): void;

    public function release(DeployPlan $plan, EnvironmentName $env): TargetStatus;

    public function rollback(DeployPlan $plan, EnvironmentName $env, ?BuildManifest $targetManifest = null): TargetStatus;

    public function status(EnvironmentName $env): TargetStatus;
}
