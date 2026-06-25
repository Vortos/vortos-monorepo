<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\PullAgent\ManifestSourceInterface;
use Vortos\Deploy\PullAgent\SignedDesiredStateManifest;

final class FakeManifestSource implements ManifestSourceInterface
{
    /** @var array<string, SignedDesiredStateManifest> */
    private array $manifests = [];

    public function latest(string $env): ?SignedDesiredStateManifest
    {
        return $this->manifests[$env] ?? null;
    }

    public function set(string $env, SignedDesiredStateManifest $signed): void
    {
        $this->manifests[$env] = $signed;
    }

    public function clear(string $env): void
    {
        unset($this->manifests[$env]);
    }
}
