<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

interface ManifestPublisherInterface
{
    public function publish(SignedDesiredStateManifest $signed): void;
}
