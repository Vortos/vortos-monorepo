<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest;

use Vortos\FeatureFlags\Http\Management\Interceptor\ChangeRequestInterceptorInterface;

/**
 * Block 14 — the real change-request interceptor. Replaces the Block 13
 * {@see \Vortos\FeatureFlags\Http\Management\Interceptor\NullChangeRequestInterceptor}
 * stub (wired by {@see \Vortos\FeatureFlags\DependencyInjection\Compiler\ChangeRequestInterceptorCompilerPass}).
 *
 * A direct mutation through the management API is rejected (HTTP 202) when the target
 * flag+environment is protected; the caller must then open a change request explicitly.
 */
final class ChangeRequestInterceptorImpl implements ChangeRequestInterceptorInterface
{
    public function __construct(
        private readonly ChangeRequestPolicy $policy,
    ) {}

    public function isProtected(string $flagName, string $environment): bool
    {
        return $this->policy->shouldIntercept($flagName, $environment);
    }
}
