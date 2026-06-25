<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Registry;

use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\Permissions;

/**
 * Port: CI-time registry login step generator.
 *
 * Each provider knows how to produce a `docker/login-action` step for one
 * registry type. Implementations live in Driver\Registry\ and are collected
 * at compile time.
 *
 * The driver key matches the PipelineDefinition.registryProvider value
 * (e.g. 'ghcr', 'docker-hub', 'gcp-artifact-registry').
 */
interface CiRegistryLoginProviderInterface extends DriverInterface
{
    /**
     * Returns the pinned docker/login-action step for this registry type.
     * The step name must be stable so tests can assert on it.
     */
    public function loginStep(RegistryLoginContext $context): ActionStep;

    /**
     * Additional GitHub Actions job permissions needed by this registry type
     * beyond the base `contents: read`.
     *
     * GHCR via GITHUB_TOKEN needs `packages: write`.
     * Docker Hub and GCP need no extra permissions.
     */
    public function requiredPermissions(): Permissions;
}
