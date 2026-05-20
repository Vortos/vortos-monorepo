<?php

declare(strict_types=1);

namespace Vortos\Auth\TwoFactor\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface;

/**
 * Enforces #[Requires2FA] on controllers.
 *
 * Runs at TWO_FACTOR (order 650) — after auth (700), before user rate limit (625).
 * Zero reflection — reads compile-time map.
 *
 * Returns 403 with challenge URL when 2FA not verified.
 */
#[AsMiddleware(order: MiddlewareOrder::TWO_FACTOR)]
final class TwoFactorMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $protectedControllers Pre-built by TwoFactorCompilerPass.
     */
    public function __construct(
        private CurrentUserProvider       $currentUser,
        private ?TwoFactorVerifierInterface $verifier,
        private array                     $protectedControllers,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->verifier === null) {
            return $next($request);
        }

        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller === null || !in_array($controller, $this->protectedControllers, true)) {
            return $next($request);
        }

        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) {
            return $next($request); // AuthMiddleware handles 401
        }

        if (!$this->verifier->isVerified($identity, $request)) {
            return new JsonResponse(
                [
                    'error'         => 'Two-Factor Authentication Required',
                    'message'       => 'This action requires 2FA verification.',
                    'challenge_url' => $this->verifier->getChallengeUrl(),
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
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
