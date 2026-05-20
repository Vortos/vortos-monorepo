<?php

declare(strict_types=1);

namespace Vortos\Security\Signing\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Observability\Telemetry\TelemetryRequestAttributes;
use Vortos\Security\Event\SecurityEventDispatcher;
use Vortos\Security\Event\SignatureInvalidEvent;
use Vortos\Security\Signing\SignatureVerifier;

/**
 * Enforces #[RequiresSignature] on webhook endpoints.
 *
 * Runs at SECURITY (order 900) — before CSRF (800) and auth (700).
 * Webhook endpoints typically also carry #[SkipCsrf] since they use
 * request signing instead of CSRF tokens.
 *
 * Runtime: reads compile-time route map — zero reflection.
 */
#[AsMiddleware(order: MiddlewareOrder::SECURITY)]
final class RequestSignatureMiddleware implements MiddlewareInterface
{
    /**
     * @param array $routeMap Pre-built by RequestSignatureCompilerPass.
     *                        Keys: 'ControllerClass' or 'Class::method'
     *                        Values: ['secret', 'header', 'timestamp_header', 'replay_window_seconds', 'algorithm']
     */
    public function __construct(
        private readonly SignatureVerifier        $verifier,
        private readonly SecurityEventDispatcher  $events,
        private readonly array                   $routeMap,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $key = $this->resolveRouteKey($request->attributes->get('_controller'));

        if ($key === null || !isset($this->routeMap[$key])) {
            return $next($request);
        }

        $rule   = $this->routeMap[$key];
        $secret = $this->verifier->resolveSecret($rule['secret']);

        $valid = $rule['timestamp_header'] !== ''
            ? $this->verifier->verifyWithTimestamp(
                $request,
                $secret,
                $rule['header'],
                $rule['timestamp_header'],
                $rule['replay_window_seconds'],
                $rule['algorithm'],
            )
            : $this->verifier->verify($request, $secret, $rule['header'], $rule['algorithm']);

        if (!$valid) {
            $this->events->dispatch(new SignatureInvalidEvent(
                $request->getClientIp() ?? 'unknown',
                $request->getPathInfo(),
                $rule['header'],
            ));
            $request->attributes->set(TelemetryRequestAttributes::DROP_TRACE, true);
            $request->attributes->set(TelemetryRequestAttributes::BLOCKED_REASON, 'signature');

            return new JsonResponse(
                ['error' => 'Invalid or missing request signature.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

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
