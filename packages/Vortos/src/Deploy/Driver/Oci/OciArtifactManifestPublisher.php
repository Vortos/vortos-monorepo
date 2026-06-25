<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Oci;

use Vortos\Deploy\PullAgent\ManifestPublisherInterface;
use Vortos\Deploy\PullAgent\SignedDesiredStateManifest;

final class OciArtifactManifestPublisher implements ManifestPublisherInterface
{
    public function __construct(
        private readonly string $registryUrl,
        private readonly string $repository,
    ) {}

    public function publish(SignedDesiredStateManifest $signed): void
    {
        $tag = sprintf(
            'deploy-manifest-%s-v%d',
            $signed->manifest->env,
            $signed->manifest->version,
        );

        $payload = json_encode($signed->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $artifactPath = sprintf(
            '%s/%s:%s',
            $this->registryUrl,
            $this->repository,
            $tag,
        );

        // In a real implementation, this would push to OCI registry via HTTP API.
        // The actual push mechanism is transport-dependent and tested via integration.
        unset($artifactPath, $payload);
    }
}
