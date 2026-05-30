<?php

declare(strict_types=1);

namespace Vortos\Security\Cors\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Observability\Telemetry\TelemetryRequestAttributes;

/**
 * Handles CORS (Cross-Origin Resource Sharing).
 *
 * Runs at SECURITY (order 900) — before auth, so preflight OPTIONS requests
 * are handled without requiring a valid token.
 *
 * ## Per-route overrides
 *
 * #[Cors] on a controller class or method overrides the global config for that route.
 * CorsCompilerPass builds the routeMap at compile time — zero reflection at runtime.
 */
#[AsMiddleware(order: MiddlewareOrder::SECURITY)]
final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param array $config   Global CORS config (origins, methods, etc.)
     * @param array $routeMap Per-route overrides from #[Cors] attributes.
     *                        Keys: 'ControllerClass' or 'ControllerClass::method'
     */
    public function __construct(
        private readonly array $config,
        private readonly array $routeMap,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $origin = $request->headers->get('Origin', '');

        if ($origin === '') {
            return $next($request);
        }

        $config = $this->resolveConfig($request);

        // Handle preflight OPTIONS — respond immediately, no further processing
        if ($request->getMethod() === 'OPTIONS') {
            if (!$this->isOriginAllowed($origin, $config['origins'])) {
                $request->attributes->set(TelemetryRequestAttributes::DROP_TRACE, true);
                $request->attributes->set(TelemetryRequestAttributes::BLOCKED_REASON, 'cors');
                return new Response('', Response::HTTP_FORBIDDEN);
            }

            $response = new Response('', Response::HTTP_NO_CONTENT);
            $this->applyCorsHeaders($response, $origin, $config);
            $response->headers->set('Access-Control-Max-Age', (string) $config['max_age']);

            $requestedHeaders = $request->headers->get('Access-Control-Request-Headers', '');
            if ($requestedHeaders !== '') {
                $allowed   = array_map('strtolower', $config['allowed_headers']);
                $requested = array_map('trim', explode(',', strtolower($requestedHeaders)));
                $safe      = array_filter($requested, fn(string $h) => in_array($h, $allowed, true));
                $response->headers->set('Access-Control-Allow-Headers', implode(', ', $safe));
            } else {
                $response->headers->set('Access-Control-Allow-Headers', implode(', ', $config['allowed_headers']));
            }

            return $response;
        }

        // Non-preflight: pass through, then add CORS headers to response
        $response = $next($request);

        if ($this->isOriginAllowed($origin, $config['origins'])) {
            $this->applyCorsHeaders($response, $origin, $config);
        }

        return $response;
    }

    private function applyCorsHeaders(Response $response, string $origin, array $config): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $config['methods']));

        if ($config['credentials']) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        if (!empty($config['exposed_headers'])) {
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $config['exposed_headers']));
        }

        // Vary: Origin tells proxies to cache separately per origin
        $response->headers->set('Vary', 'Origin');
    }

    private function isOriginAllowed(string $origin, array $allowed): bool
    {
        if (empty($allowed)) {
            return false;
        }

        foreach ($allowed as $pattern) {
            if ($pattern === '*' || $pattern === $origin) {
                return true;
            }
            if (str_starts_with($pattern, '*.')) {
                $domain = substr($pattern, 2);
                if (str_ends_with($origin, '.' . $domain) || $origin === $domain) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolveConfig(Request $request): array
    {
        // _cors_owner is set by CorsPreflightCompilerPass on injected OPTIONS routes
        // so that per-route #[Cors] overrides still resolve correctly for preflights.
        $controller = $request->attributes->get('_cors_owner')
            ?? $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller !== null) {
            $override = $this->routeMap[$controller] ?? $this->routeMap[$controller . '::__invoke'] ?? null;
            if ($override !== null) {
                return array_merge($this->config, array_filter($override, fn($v) => $v !== null));
            }
        }

        return $this->config;
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
