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

        // A route that already set its own Content-Security-Policy keeps it.
        //
        // This middleware runs as a response subscriber, so it is the LAST thing
        // to touch the response — which meant a blanket `set()` silently replaced
        // any policy a route had built for itself. That is backwards: the global
        // value is a default for responses nobody thought about, and a route that
        // did think about it knows strictly more.
        //
        // It matters most where the two policies cannot be reconciled by widening
        // the global one. A server-rendered admin console emits a per-request
        // nonce and loads its own assets; a static application-wide policy cannot
        // express that nonce, so overwriting the console's header does not
        // loosen its policy — it breaks the page, with no script, style or fetch
        // permitted. Tightening the API's default should never be able to do that.
        //
        // Only an EXACT header-name match defers. A route that set
        // Content-Security-Policy while the app is configured report-only (or the
        // reverse) is not answering the same question, so the default still applies.
        if ($this->csp !== null && !$response->headers->has($this->csp->headerName())) {
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
