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
 * Enforces IP-scoped and Global-scoped rate limits.
 *
 * Runs at RATE_LIMIT_IP (order 750) — before AUTH (700).
 * Does not require identity — protects unauthenticated endpoints (e.g. login, register).
 *
 * Runtime: delegates to RateLimitService — zero reflection.
 */
#[AsMiddleware(order: MiddlewareOrder::RATE_LIMIT_IP)]
final class IpGlobalRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimitService $service,
        private bool $headersEnabled = true,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $denied = $this->service->enforce($request, RateLimitScope::Ip, RateLimitScope::Global);
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
