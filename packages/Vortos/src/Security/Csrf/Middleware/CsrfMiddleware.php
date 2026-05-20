<?php

declare(strict_types=1);

namespace Vortos\Security\Csrf\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
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
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->enabled && !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            $controller = $this->resolveControllerKey($request->attributes->get('_controller'));

            if ($controller === null || !$this->isSkipped($controller)) {
                if (!$this->csrf->validate($request)) {
                    $this->events->dispatch(new CsrfViolationEvent(
                        $request->getClientIp() ?? 'unknown',
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
