<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

interface ManifestSignerInterface
{
    public function sign(DesiredStateManifest $manifest): SignedDesiredStateManifest;
}
