<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\RateLimit\RateLimitScope;
use Vortos\Auth\RateLimit\RateLimitService;

/**
 * Enforces User-scoped rate limits.
 *
 * Runs at RATE_LIMIT_USER (order 625) — after AUTH (700) and TWO_FACTOR (650),
 * before AUTHORIZATION (600). Identity is fully resolved at this point.
 *
 * Runtime: delegates to RateLimitService — zero reflection.
 */
#[AsMiddleware(order: MiddlewareOrder::RATE_LIMIT_USER)]
final class UserRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimitService $service,
        private bool $headersEnabled = true,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $denied = $this->service->enforce($request, RateLimitScope::User);
        if ($denied !== null) {
            return $denied;
        }

        $response = $next($request);

        if ($this->headersEnabled) {
            $this->service->applyHeaders($request, $response);
        }

        return $response;
    }
}
