<?php

declare(strict_types=1);

namespace Vortos\Auth\ApiKey\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\ApiKey\ApiKeyService;
use Vortos\Auth\ApiKey\Attribute\RequiresApiKey;

/**
 * Authenticates requests using API keys.
 *
 * Priority 6 — same as AuthMiddleware (JWT). The two middleware are complementary:
 *  - JWT:    "Authorization: Bearer <token>"  — user authentication
 *  - ApiKey: "Authorization: ApiKey <key>"    — M2M / service authentication
 *
 * When a JWT is present and valid, AuthMiddleware satisfies the auth check and
 * this middleware is not reached for JWT-protected routes. When an ApiKey header
 * is present, this middleware validates it and populates request attributes so
 * downstream code knows the identity.
 *
 * Routes with #[RequiresApiKey] REQUIRE a valid API key (rejects JWT-only auth).
 * Routes without #[RequiresApiKey] are not affected by this middleware.
 *
 * Runtime: reads pre-built compile-time route map — zero reflection.
 */
final class ApiKeyAuthMiddleware implements EventSubscriberInterface
{
    private const HEADER_PREFIX = 'ApiKey ';

    /**
     * @param array $routeMap Pre-built by ApiKeyCompilerPass.
     *                        Keys: FQCN or 'Class::method'
     *                        Values: ['scopes' => [...]]
     */
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
        private readonly array         $routeMap,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 6],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request   = $event->getRequest();
        $routeKey  = $this->resolveRouteKey($request->attributes->get('_controller'));

        if ($routeKey === null || !isset($this->routeMap[$routeKey])) {
            return;
        }

        $rule            = $this->routeMap[$routeKey];
        $requiredScopes  = $rule['scopes'] ?? [];

        $authHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, self::HEADER_PREFIX)) {
            $event->setResponse(new JsonResponse(
                ['error' => 'API key required.', 'message' => 'Send: Authorization: ApiKey <key>'],
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'ApiKey'],
            ));
            return;
        }

        $rawKey = substr($authHeader, strlen(self::HEADER_PREFIX));
        $record = $this->apiKeyService->validate($rawKey, $requiredScopes);

        if ($record === null) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Invalid, expired, or insufficient-scope API key.'],
                Response::HTTP_UNAUTHORIZED,
            ));
            return;
        }

        // Expose the validated key record to controllers and downstream middleware
        $request->attributes->set('_api_key_record', $record);
        $request->attributes->set('_api_key_user_id', $record->userId);
        $request->attributes->set('_api_key_scopes', $record->scopes);
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
