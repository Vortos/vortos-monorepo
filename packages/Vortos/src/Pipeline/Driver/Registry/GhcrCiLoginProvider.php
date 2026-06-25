<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Driver\Registry;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Pipeline\Builder\KnownActionFactory;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\Permission;
use Vortos\Pipeline\Model\PermissionAccess;
use Vortos\Pipeline\Model\PermissionScope;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Registry\CiRegistryLoginCapability;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderInterface;
use Vortos\Pipeline\Registry\RegistryLoginContext;

/**
 * CI login provider for GitHub Container Registry (ghcr.io).
 *
 * Uses the built-in GITHUB_TOKEN — no user-configured secrets required.
 * Requires packages: write on the build job.
 */
#[AsDriver('ghcr')]
final class GhcrCiLoginProvider implements CiRegistryLoginProviderInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            CiRegistryLoginCapability::UsesBuiltinToken->value => true,
        ]);
    }

    public function loginStep(RegistryLoginContext $context): ActionStep
    {
        return new ActionStep(
            'Registry login',
            KnownActionFactory::dockerLogin(),
            [
                'registry' => 'ghcr.io',
                'username' => '${{ github.actor }}',
                'password' => '${{ secrets.GITHUB_TOKEN }}',
            ],
        );
    }

    public function requiredPermissions(): Permissions
    {
        return new Permissions([
            new Permission(PermissionScope::Packages, PermissionAccess::Write),
        ]);
    }
}
