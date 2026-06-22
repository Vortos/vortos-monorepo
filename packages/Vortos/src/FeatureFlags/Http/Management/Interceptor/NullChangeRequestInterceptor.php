<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management\Interceptor;

/**
 * Stub for Block 13. Block 14 replaces this with a real policy-backed interceptor.
 */
final class NullChangeRequestInterceptor implements ChangeRequestInterceptorInterface
{
    public function isProtected(string $flagName, string $environment): bool
    {
        return false;
    }
}
