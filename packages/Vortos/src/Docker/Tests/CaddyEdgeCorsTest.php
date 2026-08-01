<?php

declare(strict_types=1);

namespace Vortos\Docker\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Docker\Service\DockerFilePublisher;

/**
 * The edge answers requests it rejects before PHP runs, so it has to carry the CORS contract
 * itself for those responses.
 *
 * Without this a rate-limited request reaches the browser as an opaque network error — "no
 * Access-Control-Allow-Origin header" — with no status, no body and no Retry-After. An SPA
 * cannot tell that apart from an outage, which is how a throttled token refresh became a
 * silent sign-out. These tests pin the generated pattern, because a wrong one fails in the
 * direction nobody watches: the response is still sent, just unreadable.
 *
 * @see DockerFilePublisher
 */
final class CaddyEdgeCorsTest extends TestCase
{
    private const STUB_ROOT = __DIR__ . '/../stubs';

    /** @param list<string> $origins */
    private static function caddyfileFor(array $origins): string
    {
        $dir = sys_get_temp_dir() . '/vortos-cors-' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);

        (new DockerFilePublisher(self::STUB_ROOT))->publish(
            'frankenphp',
            $dir,
            false,
            false,
            true,
            ['features' => ['mercure' => false], 'corsOrigins' => $origins],
        );

        return (string) file_get_contents($dir . '/docker/frankenphp/Caddyfile');
    }

    public function test_the_allowlist_becomes_an_anchored_alternation(): void
    {
        $caddyfile = self::caddyfileFor(['https://app.example.com', 'https://admin.example.com']);

        // preg_quote also escapes ":" and "/", which are harmless in a regex — so pin the
        // shape (anchors, alternation, both hosts) rather than the exact escaping.
        self::assertMatchesRegularExpression(
            '/header_regexp Origin "\^\(.*app\\\\\.example\\\\\.com.*\|.*admin\\\\\.example\\\\\.com.*\)\$"/',
            $caddyfile,
        );
    }

    /** A placeholder left in the published file would match nothing and read as a typo. */
    public function test_no_placeholder_survives_publication(): void
    {
        self::assertStringNotContainsString('{{VORTOS_CORS_ORIGIN_PATTERN}}', self::caddyfileFor([]));
    }

    /**
     * An app that declares no origins gets no origin echoed. `^$` matches no Origin header, so
     * the branch never fires and rejected requests keep Caddy's own handling — the safe
     * direction to fail in.
     */
    public function test_an_empty_allowlist_matches_nothing(): void
    {
        self::assertStringContainsString('header_regexp Origin "^$"', self::caddyfileFor([]));
    }

    /**
     * The middleware honours `*` and `*.example.com`, so the edge must too. An edge that
     * disagreed with the application about who is allowed would be worse than one that says
     * nothing at all.
     */
    public function test_wildcards_are_translated_rather_than_dropped(): void
    {
        $caddyfile = self::caddyfileFor(['*.example.com']);

        self::assertStringContainsString('[a-z0-9-]+\.example\.com', $caddyfile);
    }

    public function test_a_star_allowlist_matches_any_origin(): void
    {
        self::assertStringContainsString('header_regexp Origin "^(.*)$"', self::caddyfileFor(['*']));
    }

    /**
     * A preflight is issued by the browser, not the caller, and carries no credentials. Counting
     * it halved the real budget, and a 429 on the preflight is fatal regardless of headers — the
     * request is never sent, so the endpoint disappears instead of slowing down.
     */
    public function test_preflights_are_excluded_from_the_auth_rate_limit(): void
    {
        $caddyfile = self::caddyfileFor(['https://app.example.com']);

        self::assertMatchesRegularExpression(
            '/@auth_endpoints \{\s*\n\s*path \/api\/auth\/\*\s*\n\s*not method OPTIONS/',
            $caddyfile,
        );
    }

    public function test_a_rate_limited_response_carries_a_readable_body(): void
    {
        $caddyfile = self::caddyfileFor(['https://app.example.com']);

        self::assertStringContainsString('"error":"rate_limited"', $caddyfile);
        self::assertStringContainsString('Access-Control-Allow-Origin "{http.request.header.Origin}"', $caddyfile);
    }
}
