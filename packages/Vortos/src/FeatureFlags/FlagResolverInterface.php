<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * Resolves a flag by name during evaluation — used to evaluate prerequisites without
 * the evaluator reaching into storage directly. Implementations must be cheap on the
 * hot path (memoize per request).
 */
interface FlagResolverInterface
{
    public function resolve(string $name): ?FeatureFlag;
}
