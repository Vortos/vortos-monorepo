<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * Default resolver used when no segment storage is wired (e.g. unit tests, apps that
 * never use segments). Every lookup misses → a segment rule safe-defaults to no-match.
 */
final class NullSegmentResolver implements SegmentResolverInterface
{
    public function resolve(string $name): ?Segment
    {
        return null;
    }
}
