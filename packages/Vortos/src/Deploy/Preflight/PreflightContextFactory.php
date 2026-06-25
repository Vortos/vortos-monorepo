<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Definition\LayeredDefinitionResolver;
use Vortos\Deploy\Exception\DeployAbortedException;
use Vortos\Deploy\Plan\DeployPreflightStateBuilder;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Release\ReadModel\ManifestReadModelInterface;

/**
 * Assembles a {@see PreflightContext} for an environment from the resolved
 * definition, the desired build, and current live state.
 *
 * Shared by 'deploy:doctor' and the deploy runner so the
 * context a human's doctor sees is byte-for-byte the context the deploy gate uses
 * (§5.3). Read-only: it resolves config, reads the manifest read model, and queries
 * the target's current status — it never mutates.
 */
final class PreflightContextFactory
{
    public function __construct(
        private readonly LayeredDefinitionResolver $resolver,
        private readonly ManifestReadModelInterface $manifestReadModel,
        private readonly DeployPreflightStateBuilder $stateBuilder,
        private readonly DeployTargetRegistry $targets,
    ) {}

    /**
     * @throws DeployAbortedException when no desired build manifest exists for $env
     */
    public function build(string $env): PreflightContext
    {
        $envName = new EnvironmentName($env);
        $definition = $this->resolver->resolve($env);

        $desired = $this->manifestReadModel->latestForEnvironment($env);
        if ($desired === null) {
            throw new DeployAbortedException(sprintf(
                'No desired build manifest exists for environment "%s"; cannot run preflight.',
                $env,
            ));
        }

        $activeColor = ActiveColor::None;
        $currentDigest = '';
        if ($this->targets->has($definition->host)) {
            $live = $this->targets->target($definition->host)->status($envName);
            $activeColor = $live->color;
            $currentDigest = $live->imageDigest;
        }

        $state = $this->stateBuilder->build($definition, $desired, $activeColor, $currentDigest);

        return new PreflightContext($definition, $desired, $state, $envName);
    }
}
