<?php

declare(strict_types=1);

namespace Vortos\Authorization\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Exception\AccessDeniedException;

/**
 * Enforces #[RequiresPermission] on controllers.
 *
 * Listens on kernel.request at priority 5 — after RouterListener (8)
 * and after AuthMiddleware (6). By the time this runs:
 *   - _controller is set in request attributes (from RouterListener)
 *   - UserIdentity is set in ArrayAdapter (from AuthMiddleware)
 *
 * ## Sequence per request
 *
 *   priority 8: RouterListener    — matches route, sets _controller
 *   priority 6: AuthMiddleware    — validates token, sets identity
 *   priority 5: AuthorizationMiddleware — checks permissions
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
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
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

        $controllerClass = $this->extractControllerClass($controller);

        if ($controllerClass === null || !class_exists($controllerClass)) {
            return;
        }

        $permissions = $this->getRequiredPermissions($controllerClass);

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
            $resource = $permissionAttr->resourceParam !== null
                ? $request->attributes->get($permissionAttr->resourceParam)
                : null;

            if (!$this->policyEngine->can($identity, $permissionAttr->permission, $resource)) {
                $event->setResponse(new JsonResponse(
                    [
                        'error'      => 'Forbidden',
                        'message'    => sprintf(
                            'You do not have permission to perform this action.',
                        ),
                        'permission' => $permissionAttr->permission,
                    ],
                    Response::HTTP_FORBIDDEN,
                ));
                return;
            }
        }
    }

    /**
     * @return RequiresPermission[]
     */
    private function getRequiredPermissions(string $controllerClass): array
    {
        try {
            $reflection = new \ReflectionClass($controllerClass);
            $attrs = $reflection->getAttributes(RequiresPermission::class);
            return array_map(fn($attr) => $attr->newInstance(), $attrs);
        } catch (\Throwable) {
            return [];
        }
    }

    private function extractControllerClass(mixed $controller): ?string
    {
        if (is_string($controller)) {
            return explode('::', $controller)[0];
        }
        if (is_array($controller)) {
            return is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
        }
        if (is_object($controller)) {
            return get_class($controller);
        }
        return null;
    }
}
