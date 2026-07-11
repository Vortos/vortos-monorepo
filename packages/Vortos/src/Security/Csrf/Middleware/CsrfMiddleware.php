<?php

declare(strict_types=1);

namespace Vortos\Security\Csrf\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\IpResolverInterface;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Observability\Telemetry\TelemetryRequestAttributes;
use Vortos\Security\Csrf\CsrfTokenService;
use Vortos\Security\Event\CsrfViolationEvent;
use Vortos\Security\Event\SecurityEventDispatcher;

/**
 * CSRF protection using the double-submit cookie pattern.
 *
 * Runs at CSRF (order 800) — after security checks (900), before auth (700).
 *
 * Routes in $skipControllers (built by CsrfCompilerPass from #[SkipCsrf]) bypass
 * validation — use for stateless JWT endpoints and webhook receivers.
 *
 * Safe methods (GET/HEAD/OPTIONS) are always allowed — CSRF only applies to
 * state-changing requests.
 */
#[AsMiddleware(order: MiddlewareOrder::CSRF)]
final class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $skipControllers Controller FQCNs or 'Class::method' strings
     *                                       pre-built by CsrfCompilerPass at compile time.
     */
    public function __construct(
        private readonly CsrfTokenService        $csrf,
        private readonly SecurityEventDispatcher  $events,
        private readonly bool                    $enabled,
        private readonly array                   $skipControllers,
        private readonly IpResolverInterface      $ipResolver = new \Vortos\Http\IpResolver\RemoteAddrIpResolver(),
        /**
         * When true, requests carrying an `Authorization: Bearer` token bypass CSRF
         * validation. Such requests are token-authenticated, not cookie-authenticated:
         * a cross-site attacker cannot read the token (same-origin policy) nor set the
         * Authorization header on a forged navigation/form post, and a forged request
         * without a valid Bearer is rejected by the auth layer regardless. CSRF's
         * double-submit only defends ambient *cookie* credentials, so it adds no
         * protection here — only the fragility of cross-origin cookie plumbing.
         * Leave false for cookie/session-authenticated apps.
         */
        private readonly bool                    $skipWhenBearerAuth = false,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->enabled && !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            $controller = $this->resolveControllerKey($request->attributes->get('_controller'));

            if (($controller === null || !$this->isSkipped($controller)) && !$this->isBearerAuthenticated($request)) {
                if (!$this->csrf->validate($request)) {
                    $this->events->dispatch(new CsrfViolationEvent(
                        $this->ipResolver->resolve($request),
                        $request->getPathInfo(),
                        $request->getMethod(),
                    ));
                    $request->attributes->set(TelemetryRequestAttributes::DROP_TRACE, true);
                    $request->attributes->set(TelemetryRequestAttributes::BLOCKED_REASON, 'csrf');

                    return new JsonResponse(
                        [
                            'error'   => 'CSRF token invalid or missing.',
                            'message' => 'Include the token from the ' . $this->csrf->cookieName() . ' cookie '
                                . 'in the ' . $this->csrf->headerName() . ' request header.',
                        ],
                        Response::HTTP_FORBIDDEN,
                    );
                }
            }
        }

        $response = $next($request);

        // Issue a CSRF cookie if not yet present
        if ($this->enabled && !$this->csrf->hasCookie($request)) {
            $this->csrf->issue($response);
        }

        return $response;
    }

    /**
     * True when CSRF should be skipped because the request is Bearer-token
     * authenticated (see $skipWhenBearerAuth). Only presence of the header is
     * needed — token validity is enforced downstream by the auth middleware.
     */
    private function isBearerAuthenticated(Request $request): bool
    {
        return $this->skipWhenBearerAuth
            && str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    private function isSkipped(string $controllerKey): bool
    {
        foreach ($this->skipControllers as $skip) {
            if ($skip === $controllerKey || str_starts_with($controllerKey, $skip . '::')) {
                return true;
            }
        }
        return false;
    }

    private function resolveControllerKey(mixed $controller): ?string
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
