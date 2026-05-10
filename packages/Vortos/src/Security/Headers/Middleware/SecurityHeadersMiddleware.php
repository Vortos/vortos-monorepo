<?php

declare(strict_types=1);

namespace Vortos\Security\Headers\Middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Security\Headers\ContentSecurityPolicyBuilder;

/**
 * Appends HTTP security headers to every response.
 *
 * Runs at priority 100 — outermost subscriber — so headers are added even when
 * earlier middleware (IP filter, CSRF, auth) short-circuits with an error response.
 *
 * All header values are frozen at construct time from the compile-time config array.
 * The onKernelResponse handler does one HashMap lookup per header — zero computation.
 */
final class SecurityHeadersMiddleware implements EventSubscriberInterface
{
    /** @var array<string, string> Pre-built header name => value map */
    private array $headers;

    public function __construct(
        array $config,
        private readonly ?ContentSecurityPolicyBuilder $csp,
    ) {
        $this->headers = $this->buildHeaders($config);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 100],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        foreach ($this->headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        if ($this->csp !== null) {
            $response->headers->set($this->csp->headerName(), $this->csp->headerValue());
        }
    }

    private function buildHeaders(array $config): array
    {
        $headers = [];
        $h       = $config;

        // Strict-Transport-Security
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

        // X-Frame-Options
        if (!empty($h['x_frame_options'])) {
            $headers['X-Frame-Options'] = $h['x_frame_options'];
        }

        // X-Content-Type-Options
        if ($h['x_content_type_nosniff']) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }

        // Referrer-Policy
        if (!empty($h['referrer_policy'])) {
            $headers['Referrer-Policy'] = $h['referrer_policy'];
        }

        // Permissions-Policy
        if (!empty($h['permissions_policy'])) {
            $headers['Permissions-Policy'] = $this->buildPermissionsPolicy($h['permissions_policy']);
        }

        // Cross-Origin-Embedder-Policy
        if (!empty($h['coep'])) {
            $headers['Cross-Origin-Embedder-Policy'] = $h['coep'];
        }

        // Cross-Origin-Opener-Policy
        if (!empty($h['coop'])) {
            $headers['Cross-Origin-Opener-Policy'] = $h['coop'];
        }

        // Cross-Origin-Resource-Policy
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
                $quoted = array_map(fn($o) => '"' . $o . '"', $origins);
                $parts[] = $feature . '=(' . implode(' ', $quoted) . ')';
            }
        }
        return implode(', ', $parts);
    }
}
