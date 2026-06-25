<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Registry;

use Vortos\Pipeline\Definition\PipelineDefinition;

/**
 * Context passed to CiRegistryLoginProviderInterface::loginStep().
 *
 * Providers extract what they need: the repository host for GCP, the OIDC
 * flag for permission decisions, etc.
 */
final readonly class RegistryLoginContext
{
    public function __construct(
        public readonly PipelineDefinition $definition,
    ) {}

    /**
     * The hostname portion of imageRepository (e.g. "ghcr.io", "europe-west4-docker.pkg.dev").
     * Returns empty string when imageRepository is null.
     */
    public function registryHost(): string
    {
        $repo = $this->definition->imageRepository;
        if ($repo === null) {
            return '';
        }

        return explode('/', $repo)[0];
    }
}
