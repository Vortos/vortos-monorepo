<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Interceptor;

interface ChangeRequestInterceptorInterface
{
    /**
     * Returns true if the flag mutation for the given flag+env should be gated behind
     * a change request (returns 202 with redirect info instead of applying immediately).
     * Block 14 provides the real implementation.
     */
    public function isProtected(string $flagName, string $environment): bool;
}
