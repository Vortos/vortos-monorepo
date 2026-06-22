<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest;

use Vortos\FeatureFlags\ChangeRequest\Storage\EnvironmentProtectionStorageInterface;

final class ChangeRequestPolicy
{
    public function __construct(
        private readonly EnvironmentProtectionStorageInterface $protectionStorage,
    ) {}

    public function shouldIntercept(string $flagName, string $environment, ?bool $flagOverride = null): bool
    {
        if ($flagOverride !== null) {
            return $flagOverride;
        }

        $protection = $this->protectionStorage->findForEnvironment($environment);

        if ($protection === null) {
            // Default: production is protected
            return $environment === 'production';
        }

        return $protection->protected;
    }

    public function requiredApprovals(string $environment): int
    {
        $protection = $this->protectionStorage->findForEnvironment($environment);

        return $protection?->requiredApprovals ?? 1;
    }

    public function requestTtl(string $environment): int
    {
        $protection = $this->protectionStorage->findForEnvironment($environment);

        return $protection?->requestTtlSeconds ?? 604800;
    }
}
