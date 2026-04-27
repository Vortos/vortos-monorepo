<?php
declare(strict_types=1);

namespace Vortos\Authorization\Ownership\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Ownership\Contract\OwnershipPolicyInterface;

/**
 * Enforces #[RequiresOwnership] and #[RequiresOwnershipOrPermission].
 * Priority 4.5 — between authorization (5) and feature access (4).
 * Zero reflection — reads compile-time map.
 */
final class OwnershipMiddleware implements EventSubscriberInterface
{
    /**
     * @param array<string, array{type: 'ownership'|'ownership_or_permission', policy: string, override: ?string}> $routeMap
     * @param array<string, OwnershipPolicyInterface> $policies
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private PolicyEngine $policyEngine,
        private array $routeMap,
        private array $policies,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 45]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller === null || !isset($this->routeMap[$controller])) return;

        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) return;

        $rule = $this->routeMap[$controller];
        $policyClass = $rule['policy'];

        if (!isset($this->policies[$policyClass])) return;

        $policy = $this->policies[$policyClass];
        $resourceId = $policy->getResourceIdFrom($request);
        $isOwner = $policy->isOwner($identity, $resourceId);

        if ($rule['type'] === 'ownership') {
            if (!$isOwner) {
                $event->setResponse(new JsonResponse(
                    ['error' => 'Forbidden', 'message' => 'You do not own this resource.'],
                    Response::HTTP_FORBIDDEN,
                ));
            }
            return;
        }

        // ownership_or_permission — check override permission
        if (!$isOwner) {
            $override = $rule['override'];
            if ($override && !$this->policyEngine->can($identity, $override)) {
                $event->setResponse(new JsonResponse(
                    ['error' => 'Forbidden', 'message' => 'You do not own this resource and lack the required permission.'],
                    Response::HTTP_FORBIDDEN,
                ));
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
