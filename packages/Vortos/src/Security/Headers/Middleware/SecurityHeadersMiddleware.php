<?php

declare(strict_types=1);

namespace Vortos\Security\Headers\Middleware;

use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Security\Headers\ContentSecurityPolicyBuilder;

/**
 * Appends HTTP security headers to every response.
 *
 * Runs at OUTERMOST (order 1000) — so headers are added to all responses,
 * including error responses from inner middleware that short-circuit.
 *
 * All header values are frozen at construct time from the compile-time config array.
 * The handle() after-phase does one HashMap lookup per header — zero computation.
 */
#[AsMiddleware(order: MiddlewareOrder::OUTERMOST)]
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @var array<string, string> Pre-built header name => value map */
    private array $headers;

    public function __construct(
        array $config,
        private readonly ?ContentSecurityPolicyBuilder $csp,
    ) {
        $this->headers = $this->buildHeaders($config);
    }

    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        if ($this->csp !== null) {
            $response->headers->set($this->csp->headerName(), $this->csp->headerValue());
        }

        return $response;
    }

    private function buildHeaders(array $config): array
    {
        $headers = [];
        $h       = $config;

        if ($h['hsts']) {
            $hsts = 'max-age=' . $h['hsts_max_age'];
            if ($h['hsts_sub_domains']) {
                $hsts .= '; includeSubDomains';
            }
            if ($h['hsts_preload']) {
                $hsts .= '; preload';
            }
            $headers['Strict-Transport-Security'] = $hsts;
        }

        if (!empty($h['x_frame_options'])) {
            $headers['X-Frame-Options'] = $h['x_frame_options'];
        }

        if ($h['x_content_type_nosniff']) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }

        if (!empty($h['referrer_policy'])) {
            $headers['Referrer-Policy'] = $h['referrer_policy'];
        }

        if (!empty($h['permissions_policy'])) {
            $headers['Permissions-Policy'] = $this->buildPermissionsPolicy($h['permissions_policy']);
        }

        if (!empty($h['coep'])) {
            $headers['Cross-Origin-Embedder-Policy'] = $h['coep'];
        }

        if (!empty($h['coop'])) {
            $headers['Cross-Origin-Opener-Policy'] = $h['coop'];
        }

        if (!empty($h['corp'])) {
            $headers['Cross-Origin-Resource-Policy'] = $h['corp'];
        }

        // X-XSS-Protection — deprecated but still expected by some scanners
        $headers['X-XSS-Protection'] = '0';

        return $headers;
    }

    private function buildPermissionsPolicy(array $features): string
    {
        $parts = [];
        foreach ($features as $feature => $origins) {
            if (empty($origins)) {
                $parts[] = $feature . '=()';
            } else {
                $quoted  = array_map(fn($o) => '"' . $o . '"', $origins);
                $parts[] = $feature . '=(' . implode(' ', $quoted) . ')';
            }
        }
        return implode(', ', $parts);
    }
}
