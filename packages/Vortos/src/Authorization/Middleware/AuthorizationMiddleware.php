<?php

declare(strict_types=1);

namespace Vortos\Authorization\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Engine\PolicyEngine;

/**
 * Enforces #[RequiresPermission] on controllers.
 *
 * Listens on kernel.request at priority 3 — after TwoFactor (5) and RateLimitUser (4).
 * By the time this runs:
 *   - _controller is set in request attributes (from RouterListener)
 *   - UserIdentity is set in ArrayAdapter (from AuthMiddleware)
 *   - 2FA is verified (from TwoFactorMiddleware)
 *
 * ## Sequence per request
 *
 *   priority 6: AuthMiddleware    — validates token, sets identity
 *   priority 5: TwoFactorMiddleware — 2FA gate
 *   priority 4: RateLimitUser     — per-user rate limit
 *   priority 3: AuthorizationMiddleware — checks permissions
 *   priority 0: ControllerResolver → controller executes
 *
 * ## Unauthenticated requests
 *
 * If the controller has #[RequiresPermission] and the request is unauthenticated,
 * returns 401 (not 403) — the user needs to authenticate first.
 * AuthMiddleware may have already returned 401 if the controller also has
 * #[RequiresAuth], but #[RequiresPermission] implies auth so we handle it here too.
 *
 * ## Resource loading
 *
 * If #[RequiresPermission] specifies resourceParam, the middleware reads that
 * route parameter from request attributes and passes it to PolicyEngine::can()
 * as the $resource argument. This enables ownership and federation scope checks.
 *
 * For complex resource loading (loading from DB), implement a ResourceLoaderInterface
 * and register it — see the backlog.
 */
final class AuthorizationMiddleware implements EventSubscriberInterface
{
    public function __construct(
        private PolicyEngine $policyEngine,
        private CurrentUserProvider $currentUser,
        private ControllerPermissionMap $permissionMap,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 3],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $controller = $request->attributes->get('_controller');

        if ($controller === null) {
            return;
        }

        [$controllerClass, $controllerMethod] = $this->extractControllerReference($controller);

        if ($controllerClass === null || !class_exists($controllerClass)) {
            return;
        }

        $permissions = $this->permissionMap->forController($controllerClass, $controllerMethod);

        if (empty($permissions)) {
            return;
        }

        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Unauthorized', 'message' => 'Authentication required.'],
                Response::HTTP_UNAUTHORIZED,
            ));
            return;
        }

        foreach ($permissions as $permissionAttr) {
            $resource = $permissionAttr['resourceParam'] !== null
                ? $request->attributes->get($permissionAttr['resourceParam'])
                : null;

            $allowed = $permissionAttr['scope'] === null
                ? $this->policyEngine->can($identity, $permissionAttr['permission'], $resource)
                : $this->policyEngine->canScoped(
                    $identity,
                    $permissionAttr['permission'],
                    $this->resolveScopes($request, $permissionAttr['scope']),
                    $permissionAttr['scopeMode'],
                    $resource,
                );

            if (!$allowed) {
                $event->setResponse(new JsonResponse(
                    [
                        'error'      => 'Forbidden',
                        'message'    => sprintf(
                            'You do not have permission to perform this action.',
                        ),
                        'permission' => $permissionAttr['permission'],
                    ],
                    Response::HTTP_FORBIDDEN,
                ));
                return;
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

    /**
     * @return array<string, string>
     */
    private function resolveScopes(\Symfony\Component\HttpFoundation\Request $request, string|array $scope): array
    {
        $scopes = [];

        foreach ((array) $scope as $scopeName) {
            if (!is_string($scopeName) || $scopeName === '') {
                continue;
            }

            $value = $this->requestScopeValue($request, $scopeName);

            if ($value !== null && $value !== '') {
                $scopes[$scopeName] = $value;
            }
        }

        return $scopes;
    }

    private function requestScopeValue(\Symfony\Component\HttpFoundation\Request $request, string $scopeName): ?string
    {
        foreach ([$scopeName, $scopeName . 'Id', $scopeName . '_id'] as $attribute) {
            $value = $request->attributes->get($attribute);

            if (is_scalar($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
