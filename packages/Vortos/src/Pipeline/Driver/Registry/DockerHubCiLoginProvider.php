<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Driver\Registry;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Pipeline\Builder\KnownActionFactory;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Registry\CiRegistryLoginCapability;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderInterface;
use Vortos\Pipeline\Registry\RegistryLoginContext;

/**
 * CI login provider for Docker Hub (docker.io).
 *
 * Requires DOCKER_USERNAME and DOCKER_TOKEN secrets configured in GitHub Actions.
 * No extra job permissions are needed beyond the default read-only set.
 */
#[AsDriver('docker-hub')]
final class DockerHubCiLoginProvider implements CiRegistryLoginProviderInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            CiRegistryLoginCapability::UsesBuiltinToken->value => false,
        ]);
    }

    public function loginStep(RegistryLoginContext $context): ActionStep
    {
        return new ActionStep(
            'Registry login',
            KnownActionFactory::dockerLogin(),
            [
                'registry' => 'docker.io',
                'username' => '${{ secrets.DOCKER_USERNAME }}',
                'password' => '${{ secrets.DOCKER_TOKEN }}',
            ],
        );
    }

    public function requiredPermissions(): Permissions
    {
        return new Permissions([]);
    }
}
