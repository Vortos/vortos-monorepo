<?php

declare(strict_types=1);

namespace Vortos\Auth\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Contract\TokenFreshnessGuardInterface;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Jwt\ValidatedToken;
use Vortos\Auth\Session\SessionLivenessGuard;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

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
        private JwtService                     $jwtService,
        private ArrayAdapter                   $arrayAdapter,
        private array                          $protectedControllers = [],
        private ?TokenFreshnessGuardInterface  $freshnessGuard = null,
        private ?SessionLivenessGuard          $livenessGuard = null,
        private bool                           $enforceSessionLiveness = false,
        private ?FrameworkTelemetry            $telemetry = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $validated = $this->resolveToken($request);
        $identity = $validated?->identity ?? new AnonymousIdentity();
        $authzVersion = $validated?->authzVersion ?? 0;

        $this->arrayAdapter->set('auth:identity', $identity);
        $this->arrayAdapter->set('auth:authz_version', $authzVersion);

        if ($this->routeRequiresAuth($request) && !$identity->isAuthenticated()) {
            return new JsonResponse(
                ['error' => 'Unauthorized', 'message' => 'A valid Bearer token is required.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($validated !== null && $this->freshnessGuard !== null) {
            $reason = $this->freshnessGuard->check(
                $identity->id(),
                $authzVersion,
                $validated->issuedAt,
            );
            if ($reason !== null) {
                return new JsonResponse(
                    ['error' => 'Token Stale', 'message' => $reason],
                    Response::HTTP_UNAUTHORIZED,
                    ['X-Token-Stale' => 'true'],
                );
            }
        }

        // Session-liveness: reject an access token whose session was revoked, instead of
        // waiting for it to expire. Opt-in; the guard caches + circuit-breaks the store lookup.
        if ($this->shouldCheckSessionLiveness($validated)
            && !$this->livenessGuard->isLive($validated->identity->id(), (string) $validated->sessionId)
        ) {
            $this->emitSecurityEvent('auth.session.liveness_rejected');
            return new JsonResponse(
                ['error' => 'Session Revoked', 'message' => 'This session has been signed out.'],
                Response::HTTP_UNAUTHORIZED,
                ['X-Session-Revoked' => 'true'],
            );
        }

        return $next($request);
    }

    /**
     * @phpstan-assert-if-true !null $this->livenessGuard
     * @phpstan-assert-if-true !null $validated
     */
    private function shouldCheckSessionLiveness(?ValidatedToken $validated): bool
    {
        return $this->enforceSessionLiveness
            && $this->livenessGuard !== null
            && $validated !== null
            && $validated->identity->isAuthenticated()
            && $validated->sessionId !== null;
    }

    private function emitSecurityEvent(string $event): void
    {
        $this->telemetry?->increment(
            ObservabilityModule::Security,
            FrameworkMetric::SecurityEventsTotal,
            FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Event, $event)),
        );
    }

    private function resolveToken(Request $request): ?ValidatedToken
    {
        $authHeader = $request->headers->get('Authorization', '');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        if (empty($token)) {
            return null;
        }

        try {
            return $this->jwtService->validate($token);
        } catch (\Throwable) {
            return null;
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
