<?php

declare(strict_types=1);

namespace Vortos\Authorization\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Engine\PolicyEngine;

/**
 * Enforces #[RequiresPermission] on controllers.
 *
 * Runs at AUTHORIZATION (order 600) — after TWO_FACTOR (650) and RATE_LIMIT_USER (625).
 * By the time this runs:
 *   - _controller is set in request attributes (from Router)
 *   - UserIdentity is set in ArrayAdapter (from AuthMiddleware)
 *   - 2FA is verified (from TwoFactorMiddleware)
 *
 * If the controller has #[RequiresPermission] and the request is unauthenticated,
 * returns 401 — the user needs to authenticate first.
 *
 * If #[RequiresPermission] specifies resourceParam, reads that route parameter and
 * passes it to PolicyEngine::can() as the $resource argument.
 */
#[AsMiddleware(order: MiddlewareOrder::AUTHORIZATION)]
final class AuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private PolicyEngine $policyEngine,
        private CurrentUserProvider $currentUser,
        private ControllerPermissionMap $permissionMap,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $controller = $request->attributes->get('_controller');

        if ($controller === null) {
            return $next($request);
        }

        [$controllerClass, $controllerMethod] = $this->extractControllerReference($controller);

        if ($controllerClass === null || !class_exists($controllerClass)) {
            return $next($request);
        }

        $permissions = $this->permissionMap->forController($controllerClass, $controllerMethod);

        if (empty($permissions)) {
            return $next($request);
        }

        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) {
            return new JsonResponse(
                ['error' => 'Unauthorized', 'message' => 'Authentication required.'],
                Response::HTTP_UNAUTHORIZED,
            );
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
                return new JsonResponse(
                    [
                        'error'      => 'Forbidden',
                        'message'    => 'You do not have permission to perform this action.',
                        'permission' => $permissionAttr['permission'],
                    ],
                    Response::HTTP_FORBIDDEN,
                );
            }
        }

        return $next($request);
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
    private function resolveScopes(Request $request, string|array $scope): array
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

    private function requestScopeValue(Request $request, string $scopeName): ?string
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
