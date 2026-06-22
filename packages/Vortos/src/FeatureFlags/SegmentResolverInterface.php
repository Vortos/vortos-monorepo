<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * Resolves a segment by name during evaluation. Implementations MUST be cheap on the
 * hot path — bulk-load once and serve from memory; never issue a query per rule.
 */
interface SegmentResolverInterface
{
    public function resolve(string $name): ?Segment;
}
