<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\Target\ActiveColor;

final class DesiredStateApplier
{
    public function __construct(
        private readonly CurrentReleaseStoreInterface $releaseStore,
        private readonly ComposeProjectFactory $composeFactory,
    ) {}

    public function apply(DesiredStateManifest $manifest): void
    {
        $release = new CurrentRelease(
            env: $manifest->env,
            activeColor: ActiveColor::from($manifest->activeColor),
            imageDigest: $manifest->imageDigest,
            buildId: $manifest->releaseVersion,
            planHash: hash('sha256', $manifest->toCanonicalBytes()),
            recordedAt: new \DateTimeImmutable(),
            generation: $manifest->version,
        );

        $this->releaseStore->recordCurrentRelease($release);
    }
}
