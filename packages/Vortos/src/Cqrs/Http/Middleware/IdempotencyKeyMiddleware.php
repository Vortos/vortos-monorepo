<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;

/**
 * Enforces the Idempotency-Key header on controllers marked #[RequiresIdempotencyKey].
 *
 * Runs at AUTH - 10 (690) — after JWT is validated, before two-factor and authorization.
 * The controller list is pre-built by IdempotencyKeyMiddlewarePass at compile time;
 * zero reflection happens at runtime.
 *
 * Returns HTTP 422 if the header is absent on an enforced endpoint.
 *
 * @see \Vortos\Cqrs\Http\Attribute\RequiresIdempotencyKey
 * @see \Vortos\Cqrs\Http\Middleware\Compiler\IdempotencyKeyMiddlewarePass
 */
#[AsMiddleware(order: MiddlewareOrder::AUTH - 10)]
final class IdempotencyKeyMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $enforcedControllers Pre-built by IdempotencyKeyMiddlewarePass.
     */
    public function __construct(
        private array $enforcedControllers = [],
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->routeRequiresKey($request) && !$request->headers->has('Idempotency-Key')) {
            return new JsonResponse(
                [
                    'error'   => 'Missing Idempotency-Key',
                    'message' => 'This endpoint requires an Idempotency-Key header. '
                        . 'Generate a UUID per logical operation and include it with every attempt.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $next($request);
    }

    private function routeRequiresKey(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');

        if ($controller === null) {
            return false;
        }

        $class = $this->extractControllerClass($controller);

        return $class !== null && in_array($class, $this->enforcedControllers, true);
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
