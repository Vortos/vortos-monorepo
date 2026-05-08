<?php

declare(strict_types=1);

namespace Vortos\Auth\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Cache\Adapter\ArrayAdapter;

/**
 * Validates JWT tokens and enforces #[RequiresAuth] on controllers.
 *
 * Implements EventSubscriberInterface and listens to kernel.request at
 * priority 6 — after RouterListener (priority 8) has populated _controller
 * in request attributes, but before the controller is called.
 *
 * ## Why not HttpKernelInterface decorator
 *
 * A decorator wrapping the kernel runs before routing. At that point
 * _controller is not yet set so you cannot know which controller will
 * handle the request without manually calling the URL matcher a second time
 * (double routing — a performance cost).
 *
 * ## Why kernel.request at priority 6
 *
 * RouterListener runs at priority 8 and populates _controller.
 * We run at priority 6 — after routing, before the controller executes.
 * This gives us the controller class with zero extra route matching.
 *
 * ## Token resolution
 *
 * Token validation happens on every request regardless of whether the
 * route is protected. This ensures CurrentUserProvider::get() always
 * returns the correct identity — authenticated or anonymous — for any
 * component that reads it during the request lifecycle.
 *
 * ## Protected controller list
 *
 * Built at compile time by AuthCompilerPass — zero reflection at runtime.
 * @see \Vortos\Auth\Middleware\Compiler\AuthCompilerPass
 */
final class AuthMiddleware implements EventSubscriberInterface
{
    /**
     * @param list<string> $protectedControllers Pre-built by AuthCompilerPass.
     */
    public function __construct(
        private JwtService $jwtService,
        private ArrayAdapter $arrayAdapter,
        private array $protectedControllers = [],
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 6],
        ];
    }

    /**
     * Runs after RouterListener has matched the route and set _controller.
     * Validates the Bearer token and enforces #[RequiresAuth] on the controller.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Resolve identity from Bearer token — always, for all routes
        $identity = $this->resolveIdentity($request);
        $this->arrayAdapter->set('auth:identity', $identity);

        // Check if the resolved controller requires authentication
        if ($this->routeRequiresAuth($request) && !$identity->isAuthenticated()) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Unauthorized', 'message' => 'A valid Bearer token is required.'],
                Response::HTTP_UNAUTHORIZED,
            ));
        }
    }

    private function resolveIdentity(Request $request): UserIdentityInterface
    {
        $authHeader = $request->headers->get('Authorization', '');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return new AnonymousIdentity();
        }

        $token = substr($authHeader, 7);

        if (empty($token)) {
            return new AnonymousIdentity();
        }

        try {
            return $this->jwtService->validate($token);
        } catch (\Throwable) {
            return new AnonymousIdentity();
        }
    }

    /**
     * Read _controller from request attributes — already populated by RouterListener.
     * Zero extra routing cost. Zero reflection — uses compile-time list from AuthCompilerPass.
     */
    private function routeRequiresAuth(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');

        if ($controller === null) {
            return false;
        }

        $controllerClass = $this->extractControllerClass($controller);

        if ($controllerClass === null) {
            return false;
        }

        return in_array($controllerClass, $this->protectedControllers, true);
    }

    private function extractControllerClass(mixed $controller): ?string
    {
        if (is_string($controller)) {
            return explode('::', $controller)[0];
        }

        if (is_array($controller)) {
            return is_object($controller[0])
                ? get_class($controller[0])
                : $controller[0];
        }

        if (is_object($controller)) {
            return get_class($controller);
        }

        return null;
    }
}
