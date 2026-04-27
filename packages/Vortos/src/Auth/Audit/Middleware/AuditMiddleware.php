<?php
declare(strict_types=1);

namespace Vortos\Auth\Audit\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\Contract\AuditStoreInterface;
use Vortos\Auth\Identity\CurrentUserProvider;

/**
 * Records audit log entries for controllers with #[AuditLog].
 * Priority 2 — after quota (3), only fires when auth confirmed.
 * Zero reflection at runtime — reads compile-time map.
 *
 * Fires and forgets — audit failure never blocks the request.
 */
final class AuditMiddleware implements EventSubscriberInterface
{
    /**
     * @param array<string, list<array{action: string, include: list<string>}>> $routeMap
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private ?AuditStoreInterface $store,
        private array $routeMap,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 2]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        if ($this->store === null) return;

        $request = $event->getRequest();
        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller === null || !isset($this->routeMap[$controller])) return;

        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) return;

        foreach ($this->routeMap[$controller] as $rule) {
            $metadata = [];
            foreach ($rule['include'] as $param) {
                $value = $request->attributes->get($param) ?? $request->query->get($param);
                if ($value !== null) {
                    $metadata[$param] = $value;
                }
            }

            try {
                $this->store->record(AuditEntry::create(
                    userId: $identity->id(),
                    action: $rule['action'],
                    resourceId: $request->attributes->get('id'),
                    ipAddress: $request->getClientIp() ?? '',
                    userAgent: $request->headers->get('User-Agent', ''),
                    metadata: $metadata,
                ));
            } catch (\Throwable) {
                // Audit failure must never block the request
            }
        }
    }

    private function extractControllerClass(mixed $controller): ?string
    {
        if (is_string($controller)) return explode('::', $controller)[0];
        if (is_array($controller)) return is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
        if (is_object($controller)) return get_class($controller);
        return null;
    }
}
