<?php

declare(strict_types=1);

namespace Vortos\Security\IpFilter\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Observability\Telemetry\TelemetryRequestAttributes;
use Vortos\Security\Event\IpDeniedEvent;
use Vortos\Security\Event\SecurityEventDispatcher;
use Vortos\Security\IpFilter\IpResolver;

/**
 * Enforces IP allowlist/denylist at SECURITY (order 900) — before auth.
 *
 * Evaluation order per request:
 *  1. Per-route override — checked regardless of global enabled flag.
 *  2. If globally disabled — pass through.
 *  3. If there is a global allowlist — deny if IP not in it.
 *  4. If there is a global denylist — deny if IP is in it.
 *  5. Pass through.
 *
 * Runtime: reads pre-built compile-time map — zero reflection.
 */
#[AsMiddleware(order: MiddlewareOrder::SECURITY)]
final class IpFilterMiddleware implements MiddlewareInterface
{
    /**
     * @param array $routeMap Per-route overrides from #[AllowIp]/#[DenyIp].
     *                        Keys: controller class or 'Class::method'.
     *                        Values: ['allow' => [...], 'deny' => [...]]
     */
    public function __construct(
        private readonly IpResolver              $ipResolver,
        private readonly SecurityEventDispatcher  $events,
        private readonly bool                    $enabled,
        private readonly array                   $globalAllowlist,
        private readonly array                   $globalDenylist,
        private readonly array                   $routeMap,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $ip       = $this->ipResolver->resolve($request);
        $routeKey = $this->resolveRouteKey($request->attributes->get('_controller'));

        if ($routeKey !== null && isset($this->routeMap[$routeKey])) {
            $override = $this->routeMap[$routeKey];
            if (!$this->isAllowed($ip, $override['allow'] ?? [], $override['deny'] ?? [])) {
                return $this->deny($request, $ip);
            }
            return $next($request);
        }

        if ($this->enabled && !$this->isAllowed($ip, $this->globalAllowlist, $this->globalDenylist)) {
            return $this->deny($request, $ip);
        }

        return $next($request);
    }

    private function isAllowed(string $ip, array $allowlist, array $denylist): bool
    {
        if (!empty($allowlist) && !$this->ipResolver->matchesCidr($ip, $allowlist)) {
            return false;
        }

        if (!empty($denylist) && $this->ipResolver->matchesCidr($ip, $denylist)) {
            return false;
        }

        return true;
    }

    private function deny(Request $request, string $ip): Response
    {
        $this->events->dispatch(new IpDeniedEvent($ip, $request->getPathInfo()));
        $request->attributes->set(TelemetryRequestAttributes::DROP_TRACE, true);
        $request->attributes->set(TelemetryRequestAttributes::BLOCKED_REASON, 'ip_filter');

        return new JsonResponse(
            ['error' => 'Forbidden', 'message' => 'Access denied.'],
            Response::HTTP_FORBIDDEN,
        );
    }

    private function resolveRouteKey(mixed $controller): ?string
    {
        if (is_string($controller)) {
            return $controller;
        }
        if (is_array($controller)) {
            $class = is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
            return isset($controller[1]) ? $class . '::' . $controller[1] : $class;
        }
        if (is_object($controller)) {
            return get_class($controller);
        }
        return null;
    }
}
