<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\RedirectResponse;
use Vortos\Http\Request;
use Vortos\SchedulerAdmin\AdminConfig;

final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CurrentUserProvider $currentUser,
        private readonly AdminConfig         $config,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->isAdminRoute($request)) {
            return $next($request);
        }

        $user = $this->currentUser->get();

        if (!$user->isAuthenticated()) {
            return new RedirectResponse(
                $this->config->loginUrl . '?redirect=' . urlencode($request->getPathInfo()),
            );
        }

        if (!$user->hasRole($this->config->requiredRole)) {
            throw new ForbiddenException('Insufficient permissions to access the scheduler admin console.');
        }

        return $next($request);
    }

    private function isAdminRoute(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), $this->config->prefix);
    }
}
