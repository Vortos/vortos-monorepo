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
 * CI login provider for GCP Artifact Registry.
 *
 * Requires a GCP_SA_KEY secret containing the service account JSON.
 * The registry host is extracted from PipelineDefinition.imageRepository
 * (the first path component, e.g. "europe-west4-docker.pkg.dev").
 */
#[AsDriver('gcp-artifact-registry')]
final class GcpArtifactRegistryCiLoginProvider implements CiRegistryLoginProviderInterface
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
                'registry' => $context->registryHost(),
                'username' => '_json_key',
                'password' => '${{ secrets.GCP_SA_KEY }}',
            ],
        );
    }

    public function requiredPermissions(): Permissions
    {
        return new Permissions([]);
    }
}
