<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * Default flag resolver: every lookup misses, so a prerequisite is treated as unmet
 * (safe — the dependent flag stays off). Used when no storage is wired (unit tests).
 */
final class NullFlagResolver implements FlagResolverInterface
{
    public function resolve(string $name): ?FeatureFlag
    {
        return null;
    }
}
