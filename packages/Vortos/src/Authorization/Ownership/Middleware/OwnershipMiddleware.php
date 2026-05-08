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
 * Priority 2 — after authorization (3), before feature access (1).
 * Zero reflection — reads compile-time map.
 *
 * Route map keys: ControllerClass (class-level) or ControllerClass::method (method-level).
 * Method-level takes precedence over class-level when both are present.
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
        return [KernelEvents::REQUEST => ['onKernelRequest', 2]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        [$controllerClass, $controllerMethod] = $this->extractControllerReference($request->attributes->get('_controller'));

        if ($controllerClass === null) return;

        // Method-level takes precedence over class-level
        $methodKey = $controllerMethod !== null ? $controllerClass . '::' . $controllerMethod : null;
        $rule = ($methodKey !== null && isset($this->routeMap[$methodKey]))
            ? $this->routeMap[$methodKey]
            : ($this->routeMap[$controllerClass] ?? null);

        if ($rule === null) return;

        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) return;

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

    /**
     * @return array{0: ?class-string, 1: ?string}
     */
    private function extractControllerReference(mixed $controller): array
    {
        if (is_string($controller)) {
            $parts = explode('::', $controller, 2);
            return [$parts[0], $parts[1] ?? null];
        }
        if (is_array($controller)) {
            return [
                is_object($controller[0]) ? get_class($controller[0]) : $controller[0],
                isset($controller[1]) && is_string($controller[1]) ? $controller[1] : null,
            ];
        }
        if (is_object($controller)) {
            return [get_class($controller), '__invoke'];
        }
        return [null, null];
    }
}
