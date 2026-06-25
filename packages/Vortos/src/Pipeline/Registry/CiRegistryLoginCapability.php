<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Registry;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum CiRegistryLoginCapability: string implements CapabilityKey
{
    /**
     * Uses GitHub's built-in GITHUB_TOKEN — no user-configured secret required.
     * This is true for GHCR; false for Docker Hub, GCP, etc.
     */
    case UsesBuiltinToken = 'uses-builtin-token';

    public function key(): string
    {
        return $this->value;
    }
}
