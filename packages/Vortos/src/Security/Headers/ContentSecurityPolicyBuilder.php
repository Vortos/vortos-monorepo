<?php

declare(strict_types=1);

namespace Vortos\Security\Headers;

/**
 * Builds the Content-Security-Policy (or CSP-Report-Only) header value
 * from the frozen config array produced at compile time.
 *
 * All computation happens once in the constructor — the build() call is O(1).
 */
final class ContentSecurityPolicyBuilder
{
    private string $headerValue;
    private bool   $reportOnly;

    public function __construct(array $config)
    {
        $this->reportOnly  = $config['report_only'] ?? false;
        $this->headerValue = $this->compile($config);
    }

    public function headerName(): string
    {
        return $this->reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    public function headerValue(): string
    {
        return $this->headerValue;
    }

    private function compile(array $cfg): string
    {
        $directives = [];

        $standard = [
            'default-src'  => $cfg['default_src']  ?? [],
            'script-src'   => $cfg['script_src']   ?? [],
            'style-src'    => $cfg['style_src']    ?? [],
            'img-src'      => $cfg['img_src']      ?? [],
            'font-src'     => $cfg['font_src']     ?? [],
            'connect-src'  => $cfg['connect_src']  ?? [],
            'frame-src'    => $cfg['frame_src']    ?? [],
            'object-src'   => $cfg['object_src']   ?? [],
            'media-src'    => $cfg['media_src']    ?? [],
            'worker-src'   => $cfg['worker_src']   ?? [],
        ];

        foreach ($standard as $directive => $values) {
            if (!empty($values)) {
                $directives[] = $directive . ' ' . implode(' ', $values);
            }
        }

        foreach ($cfg['extra'] ?? [] as $directive => $values) {
            if (!empty($values)) {
                // Underscores back to hyphens.
                //
                // The standard directives above are written with hyphens because
                // they are hardcoded here. Custom ones arrive as ARRAY KEYS, and
                // Symfony's Config component normalises keys by replacing hyphens
                // with underscores — so `->directive('frame-ancestors', "'none'")`
                // reached this loop as `frame_ancestors` and was emitted verbatim.
                //
                // Browsers do not error on an unknown directive, they ignore it.
                // So the header looked correct in every review and the directive
                // simply did nothing: an application that added frame-ancestors,
                // base-uri or form-action got none of them, silently, and would
                // only find out by reading a live response character by character.
                // No CSP directive name contains an underscore, so this mapping is
                // unambiguous.
                $directives[] = str_replace('_', '-', $directive) . ' ' . implode(' ', $values);
            }
        }

        if (!empty($cfg['report_uri'])) {
            $directives[] = 'report-uri ' . $cfg['report_uri'];
        }

        if (!empty($cfg['report_to'])) {
            $directives[] = 'report-to ' . $cfg['report_to'];
        }

        return implode('; ', $directives);
    }
}
