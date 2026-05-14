<?php

declare(strict_types=1);

namespace Vortos\Security\Cors\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Observability\Telemetry\TelemetryRequestAttributes;

/**
 * Handles CORS (Cross-Origin Resource Sharing).
 *
 * Priority 95 on REQUEST — runs before auth (6) so preflight OPTIONS requests
 * are handled without requiring a valid token.
 *
 * Priority 95 on RESPONSE — adds CORS headers to actual cross-origin responses.
 * SecurityHeadersMiddleware runs at 100, so CORS headers are added before it
 * wraps them — order is fine since they target different header names.
 *
 * ## Per-route overrides
 *
 * #[Cors] on a controller class or method overrides the global config for that route.
 * CorsCompilerPass builds the routeMap at compile time — zero reflection at runtime.
 */
final class CorsMiddleware implements EventSubscriberInterface
{
    /**
     * @param array  $config    Global CORS config (origins, methods, etc.)
     * @param array  $routeMap  Per-route overrides from #[Cors] attributes.
     *                          Keys: 'ControllerClass' or 'ControllerClass::method'
     */
    public function __construct(
        private readonly array $config,
        private readonly array $routeMap,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 95],
            KernelEvents::RESPONSE => ['onKernelResponse', 95],
        ];
    }

    /**
     * Handle preflight OPTIONS requests — respond immediately, no further processing.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->getMethod() !== 'OPTIONS') {
            return;
        }

        $origin = $request->headers->get('Origin', '');
        if ($origin === '') {
            return;
        }

        $config = $this->resolveConfig($request);

        if (!$this->isOriginAllowed($origin, $config['origins'])) {
            $request->attributes->set(TelemetryRequestAttributes::DROP_TRACE, true);
            $request->attributes->set(TelemetryRequestAttributes::BLOCKED_REASON, 'cors');
            $event->setResponse(new Response('', Response::HTTP_FORBIDDEN));
            return;
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->applyCorsHeaders($response, $origin, $config);

        $response->headers->set('Access-Control-Max-Age', (string) $config['max_age']);

        $requestedHeaders = $request->headers->get('Access-Control-Request-Headers', '');
        if ($requestedHeaders !== '') {
            $allowed = array_map('strtolower', $config['allowed_headers']);
            $requested = array_map('trim', explode(',', strtolower($requestedHeaders)));
            $safe = array_filter($requested, fn(string $h) => in_array($h, $allowed, true));
            $response->headers->set('Access-Control-Allow-Headers', implode(', ', $safe));
        } else {
            $response->headers->set(
                'Access-Control-Allow-Headers',
                implode(', ', $config['allowed_headers'])
            );
        }

        $event->setResponse($response);
    }

    /**
     * Append CORS headers to non-preflight responses.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $origin  = $request->headers->get('Origin', '');

        if ($origin === '' || $request->getMethod() === 'OPTIONS') {
            return;
        }

        $config = $this->resolveConfig($request);

        if (!$this->isOriginAllowed($origin, $config['origins'])) {
            return;
        }

        $this->applyCorsHeaders($event->getResponse(), $origin, $config);
    }

    private function applyCorsHeaders(Response $response, string $origin, array $config): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set(
            'Access-Control-Allow-Methods',
            implode(', ', $config['methods'])
        );

        if ($config['credentials']) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        if (!empty($config['exposed_headers'])) {
            $response->headers->set(
                'Access-Control-Expose-Headers',
                implode(', ', $config['exposed_headers'])
            );
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
            if ($pattern === '*') {
                return true;
            }
            if ($pattern === $origin) {
                return true;
            }
            // Wildcard subdomain matching: *.example.com
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
        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller !== null) {
            $controllerKey = $this->routeMap[$controller] ?? null;
            $methodKey     = $this->routeMap[$controller . '::__invoke'] ?? null;

            $override = $controllerKey ?? $methodKey;
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
