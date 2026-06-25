<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

interface ManifestSourceInterface
{
    public function latest(string $env): ?SignedDesiredStateManifest;
}
