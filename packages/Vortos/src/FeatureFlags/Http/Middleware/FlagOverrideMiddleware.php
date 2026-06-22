<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Vortos\FeatureFlags\Explain\FlagOverrideService;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;

/**
 * Reads `X-Vortos-Flag-Override` tokens from the request and applies them via the
 * {@see FlagOverrideService} (Block 19).
 *
 * Runs after auth so we have an actor identity for auditing, before feature flags
 * so overrides are visible to the flag middleware.
 */
#[AsMiddleware(order: MiddlewareOrder::FEATURE_FLAGS - 1)]
final class FlagOverrideMiddleware implements MiddlewareInterface
{
    private const HEADER = 'X-Vortos-Flag-Override';

    public function __construct(
        private readonly FlagOverrideService $overrideService,
        private readonly FlagScopeContext $scopeContext,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->overrideService->isEnabled()) {
            return $next($request);
        }

        $tokens = $request->headers->all(self::HEADER);

        if ($tokens === []) {
            $token = $request->query->get('_flag_override');
            if (is_string($token) && $token !== '') {
                $tokens = [$token];
            }
        }

        if ($tokens === []) {
            $cookie = $request->cookies->get('vortos_flag_override');
            if (is_string($cookie) && $cookie !== '') {
                $tokens = [$cookie];
            }
        }

        $actorId = $request->attributes->get('_user_id');

        foreach ($tokens as $token) {
            if (is_string($token) && $token !== '') {
                $this->overrideService->applyFromToken(
                    $token,
                    $this->scopeContext->environment(),
                    is_string($actorId) ? $actorId : null,
                );
            }
        }

        return $next($request);
    }
}
