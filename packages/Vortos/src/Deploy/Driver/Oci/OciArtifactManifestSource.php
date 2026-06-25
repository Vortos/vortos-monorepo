<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Oci;

use Vortos\Deploy\PullAgent\ManifestSourceInterface;
use Vortos\Deploy\PullAgent\SignedDesiredStateManifest;

final class OciArtifactManifestSource implements ManifestSourceInterface
{
    public function __construct(
        private readonly string $registryUrl,
        private readonly string $repository,
    ) {}

    public function latest(string $env): ?SignedDesiredStateManifest
    {
        // In a real implementation, this would pull the latest manifest artifact from OCI registry.
        // The actual pull mechanism is transport-dependent and tested via integration.
        return null;
    }
}
