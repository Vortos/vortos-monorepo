<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Release\Manifest\BuildManifest;

/**
 * Read-only input to every preflight check. Assembled once by the doctor (or its
 * caller) from the resolved definition, the desired build, and current live state.
 *
 * It carries no infrastructure handles — checks receive registries/validators via
 * their own constructors. This keeps the context a pure data carrier and makes the
 * doctor trivially testable with hand-built contexts.
 */
final readonly class PreflightContext
{
    public function __construct(
        public DeploymentDefinition $definition,
        public BuildManifest $desiredManifest,
        public CurrentDeployState $currentState,
        public EnvironmentName $environment,
    ) {}
}
