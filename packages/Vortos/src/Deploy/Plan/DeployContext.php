<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Release\Manifest\BuildManifest;

final readonly class DeployContext
{
    public function __construct(
        public DeploymentDefinition $definition,
        public BuildManifest $desiredManifest,
        public CurrentDeployState $currentState,
    ) {}
}
