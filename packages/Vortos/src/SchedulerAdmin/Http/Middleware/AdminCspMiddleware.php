<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Http\Middleware;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\Request;
use Vortos\SchedulerAdmin\AdminConfig;

final class AdminCspMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!str_starts_with($request->getPathInfo(), $this->config->prefix)) {
            return $next($request);
        }

        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('_csp_nonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data:",
            "connect-src 'self'",
            "font-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "object-src 'none'",
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
