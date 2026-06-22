<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Vortos\FeatureFlagsAdmin\AdminConfig;
use Vortos\FeatureFlagsAdmin\Security\CsrfTokenManager;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Request;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly CsrfTokenManager $csrf,
        private readonly AdminConfig $config,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!str_starts_with($request->getPathInfo(), $this->config->prefix)) {
            return $next($request);
        }

        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $token = $request->headers->get('X-CSRF-Token')
            ?? $request->request->get('_csrf_token');

        if ($token === null || !$this->csrf->isValid($token)) {
            throw new ForbiddenException('Invalid or missing CSRF token.');
        }

        return $next($request);
    }
}
