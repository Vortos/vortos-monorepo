<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
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

        return $next($request);
    }

    private function isAdminRoute(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), $this->config->prefix);
    }
}
