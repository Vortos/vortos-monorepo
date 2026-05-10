<?php

declare(strict_types=1);

namespace Vortos\Security\IpFilter\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Security\Event\IpDeniedEvent;
use Vortos\Security\Event\SecurityEventDispatcher;
use Vortos\Security\IpFilter\IpResolver;

/**
 * Enforces IP allowlist/denylist at priority 90 — before auth.
 *
 * Evaluation order per request:
 *  1. If globally disabled — pass through.
 *  2. If there is a per-route allowlist — deny if IP not in it.
 *  3. If there is a per-route denylist — deny if IP is in it.
 *  4. If there is a global allowlist — deny if IP not in it.
 *  5. If there is a global denylist — deny if IP is in it.
 *  6. Pass through.
 *
 * Runtime: reads pre-built compile-time map — zero reflection.
 */
final class IpFilterMiddleware implements EventSubscriberInterface
{
    /**
     * @param array $routeMap Per-route overrides from #[AllowIp]/#[DenyIp].
     *                        Keys: controller class or 'Class::method'.
     *                        Values: ['allow' => [...], 'deny' => [...]]
     */
    public function __construct(
        private readonly IpResolver $ipResolver,
        private readonly SecurityEventDispatcher $events,
        private readonly bool $enabled,
        private readonly array $globalAllowlist,
        private readonly array $globalDenylist,
        private readonly array $routeMap,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 90],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $ip      = $this->ipResolver->resolve($request);

        // Per-route overrides — checked regardless of global enabled flag
        $routeKey = $this->resolveRouteKey($request->attributes->get('_controller'));
        if ($routeKey !== null && isset($this->routeMap[$routeKey])) {
            $override = $this->routeMap[$routeKey];
            if (!$this->isAllowed($ip, $override['allow'] ?? [], $override['deny'] ?? [])) {
                $this->deny($event, $ip);
                return;
            }
            return; // Per-route override matched and passed — done
        }

        if (!$this->enabled) {
            return;
        }

        if (!$this->isAllowed($ip, $this->globalAllowlist, $this->globalDenylist)) {
            $this->deny($event, $ip);
        }
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

    private function deny(RequestEvent $event, string $ip): void
    {
        $this->events->dispatch(new IpDeniedEvent($ip, $event->getRequest()->getPathInfo()));

        $event->setResponse(new JsonResponse(
            ['error' => 'Forbidden', 'message' => 'Access denied.'],
            Response::HTTP_FORBIDDEN,
        ));
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
