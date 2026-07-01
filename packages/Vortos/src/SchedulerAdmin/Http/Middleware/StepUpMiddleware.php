<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\RedirectResponse;
use Vortos\Http\Request;
use Vortos\SchedulerAdmin\AdminConfig;
use Vortos\SchedulerAdmin\Security\StepUpGuard;

/**
 * Enforces 2FA step-up and token freshness on sensitive admin routes.
 *
 * A route is "sensitive" if it is a mutating method (POST/PUT/PATCH/DELETE)
 * AND its path ends with one of the designated sensitive suffixes.
 *
 * Runs after CsrfMiddleware (lower priority number = later in chain).
 */
final class StepUpMiddleware implements MiddlewareInterface
{
    private const SENSITIVE_SUFFIXES = [
        '/create',
        '/edit',
        '/delete',
        '/run-now',
        '/approve',
        '/reject',
    ];

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly CurrentUserProvider $currentUser,
        private readonly StepUpGuard         $stepUpGuard,
        private readonly AdminConfig         $config,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!str_starts_with($request->getPathInfo(), $this->config->prefix)) {
            return $next($request);
        }

        if (!$this->isSensitiveRoute($request)) {
            return $next($request);
        }

        $user = $this->currentUser->get();

        if (!$this->stepUpGuard->check2FA($user)) {
            $redirect = $this->config->twoFaChallengeUrl
                . '?redirect=' . urlencode($request->getPathInfo());

            return new RedirectResponse($redirect);
        }

        if (!$this->stepUpGuard->checkFreshness($user)) {
            $redirect = $this->config->loginUrl
                . '?require_fresh=1&redirect=' . urlencode($request->getPathInfo());

            return new RedirectResponse($redirect);
        }

        return $next($request);
    }

    private function isSensitiveRoute(Request $request): bool
    {
        if (!in_array($request->getMethod(), self::MUTATING_METHODS, true)) {
            return false;
        }

        $path = rtrim($request->getPathInfo(), '/');

        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
