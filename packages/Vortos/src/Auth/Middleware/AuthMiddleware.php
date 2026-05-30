<?php

declare(strict_types=1);

namespace Vortos\Auth\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Jwt\ValidatedToken;
use Vortos\Cache\Adapter\ArrayAdapter;

/**
 * Validates JWT tokens and enforces #[RequiresAuth] on controllers.
 *
 * Runs at AUTH (order 700) — after IP filtering, CSRF, and IP rate limiting,
 * but before 2FA, authorization, and user rate limiting.
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
#[AsMiddleware(order: MiddlewareOrder::AUTH)]
final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $protectedControllers Pre-built by AuthCompilerPass.
     */
    public function __construct(
        private JwtService   $jwtService,
        private ArrayAdapter $arrayAdapter,
        private array        $protectedControllers = [],
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        [$identity, $authzVersion] = $this->resolveIdentity($request);
        $this->arrayAdapter->set('auth:identity', $identity);
        $this->arrayAdapter->set('auth:authz_version', $authzVersion);

        if ($this->routeRequiresAuth($request) && !$identity->isAuthenticated()) {
            return new JsonResponse(
                ['error' => 'Unauthorized', 'message' => 'A valid Bearer token is required.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return $next($request);
    }

    /**
     * @return array{0: UserIdentityInterface, 1: int}
     */
    private function resolveIdentity(Request $request): array
    {
        $authHeader = $request->headers->get('Authorization', '');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return [new AnonymousIdentity(), 0];
        }

        $token = substr($authHeader, 7);

        if (empty($token)) {
            return [new AnonymousIdentity(), 0];
        }

        try {
            $validated = $this->jwtService->validate($token);
            return [$validated->identity, $validated->authzVersion];
        } catch (\Throwable) {
            return [new AnonymousIdentity(), 0];
        }
    }

    private function routeRequiresAuth(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');

        if ($controller === null) {
            return false;
        }

        $controllerClass = $this->extractControllerClass($controller);

        return $controllerClass !== null && in_array($controllerClass, $this->protectedControllers, true);
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
