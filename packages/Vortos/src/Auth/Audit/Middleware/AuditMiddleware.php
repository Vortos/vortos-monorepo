<?php

declare(strict_types=1);

namespace Vortos\Auth\Audit\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\Contract\AuditStoreInterface;
use Vortos\Auth\Identity\CurrentUserProvider;

/**
 * Records audit log entries for controllers with #[AuditLog].
 *
 * Runs at AUTHORIZATION (order 600). The after-phase executes after the controller
 * returns — the audit log reflects what actually happened (real status code).
 *
 * Audit failure never affects the response.
 * Zero reflection at runtime — reads compile-time map.
 */
#[AsMiddleware(order: MiddlewareOrder::AUTHORIZATION)]
final class AuditMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, list<array{action: string, include: list<string>}>> $routeMap
     */
    public function __construct(
        private CurrentUserProvider   $currentUser,
        private ?AuditStoreInterface  $store,
        private array                 $routeMap,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);

        if ($this->store === null) {
            return $response;
        }

        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller === null || !isset($this->routeMap[$controller])) {
            return $response;
        }

        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) {
            return $response;
        }

        $statusCode = $response->getStatusCode();

        foreach ($this->routeMap[$controller] as $rule) {
            $metadata = ['_status' => $statusCode];

            foreach ($rule['include'] as $param) {
                $value = $request->attributes->get($param) ?? $request->query->get($param);
                if ($value !== null) {
                    $metadata[$param] = $value;
                }
            }

            try {
                $this->store->record(AuditEntry::create(
                    userId: $identity->id(),
                    action: $rule['action'],
                    resourceId: $request->attributes->get('id'),
                    ipAddress: $request->getClientIp() ?? '',
                    userAgent: $request->headers->get('User-Agent', ''),
                    metadata: $metadata,
                ));
            } catch (\Throwable) {
                // Audit failure must never affect the response
            }
        }

        return $response;
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
