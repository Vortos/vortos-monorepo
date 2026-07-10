<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface;
use Vortos\FeatureFlagsAdmin\AdminConfig;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\RedirectResponse;
use Vortos\Http\Request;

final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CurrentUserProvider $currentUser,
        private readonly AdminConfig $config,
        private readonly ?TwoFactorVerifierInterface $twoFactor = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->isAdminRoute($request)) {
            return $next($request);
        }

        $user = $this->currentUser->get();

        if (!$user->isAuthenticated()) {
            return new RedirectResponse('/login?redirect=' . urlencode($request->getPathInfo()));
        }

        if (!$user->hasRole($this->config->requiredRole)) {
            throw new ForbiddenException('Insufficient permissions to access the flags admin console.');
        }

        // Step-up: require a 2FA-verified session for the admin console. Enforced
        // fail-closed — a missing verifier means deny, never silently allow.
        if ($this->config->require2fa) {
            if ($this->twoFactor === null) {
                throw new ForbiddenException('Two-factor verification is required but not configured for the flags admin console.');
            }

            if (!$this->twoFactor->isVerified($user, $request)) {
                $challenge = $this->twoFactor->getChallengeUrl();
                $separator = str_contains($challenge, '?') ? '&' : '?';

                return new RedirectResponse(
                    $challenge . $separator . 'redirect=' . urlencode($request->getPathInfo()),
                );
            }
        }

        return $next($request);
    }

    private function isAdminRoute(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), $this->config->prefix);
    }
}
