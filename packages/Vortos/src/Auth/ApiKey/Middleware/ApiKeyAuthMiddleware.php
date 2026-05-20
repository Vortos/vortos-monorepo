<?php

declare(strict_types=1);

namespace Vortos\Auth\ApiKey\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\ApiKey\ApiKeyService;

/**
 * Authenticates requests using API keys.
 *
 * Runs at AUTH (order 700) — same tier as AuthMiddleware (JWT).
 *
 * Routes with #[RequiresApiKey] REQUIRE a valid API key (rejects JWT-only auth).
 * Routes without #[RequiresApiKey] are not affected by this middleware.
 *
 * Runtime: reads pre-built compile-time route map — zero reflection.
 */
#[AsMiddleware(order: MiddlewareOrder::AUTH)]
final class ApiKeyAuthMiddleware implements MiddlewareInterface
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

    public function handle(Request $request, \Closure $next): Response
    {
        $routeKey = $this->resolveRouteKey($request->attributes->get('_controller'));

        if ($routeKey === null || !isset($this->routeMap[$routeKey])) {
            return $next($request);
        }

        $rule           = $this->routeMap[$routeKey];
        $requiredScopes = $rule['scopes'] ?? [];

        $authHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, self::HEADER_PREFIX)) {
            return new JsonResponse(
                ['error' => 'API key required.', 'message' => 'Send: Authorization: ApiKey <key>'],
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'ApiKey'],
            );
        }

        $rawKey = substr($authHeader, strlen(self::HEADER_PREFIX));
        $record = $this->apiKeyService->validate($rawKey, $requiredScopes);

        if ($record === null) {
            return new JsonResponse(
                ['error' => 'Invalid, expired, or insufficient-scope API key.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $request->attributes->set('_api_key_record', $record);
        $request->attributes->set('_api_key_user_id', $record->userId);
        $request->attributes->set('_api_key_scopes', $record->scopes);

        return $next($request);
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
