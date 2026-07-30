<?php

declare(strict_types=1);

namespace Vortos\Docker\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Docker\Service\DockerFilePublisher;

/**
 * The Mercure hub block is opt-in for a blunt reason: the hub refuses to start without a signing
 * secret, so publishing it unconditionally would fail the boot of every project that does not use it.
 * These tests pin both halves of that — stripped by default, intact when asked for.
 *
 * @see DockerFilePublisher
 */
final class CaddyMercureSectionTest extends TestCase
{
    private const STUB_ROOT = __DIR__ . '/../stubs';

    private static function publishTo(string $dir, bool $withMercure): void
    {
        (new DockerFilePublisher(self::STUB_ROOT))->publish(
            'frankenphp',
            $dir,
            false,
            false,
            true,
            ['features' => ['mercure' => $withMercure]],
        );
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/vortos-caddy-' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);

        return $dir;
    }

    private static function remove(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    private static function caddyfile(string $dir): string
    {
        return (string) file_get_contents($dir . '/docker/frankenphp/Caddyfile');
    }

    public function test_hub_is_stripped_by_default(): void
    {
        $dir = self::tempDir();

        try {
            self::publishTo($dir, withMercure: false);
            $caddyfile = self::caddyfile($dir);

            // No hub directive, and no leftover markers to be uncommented by a passer-by.
            $this->assertStringNotContainsString('mercure {', $caddyfile);
            $this->assertStringNotContainsString('publisher_jwt', $caddyfile);
            $this->assertStringNotContainsString('vortos-mercure', $caddyfile);
        } finally {
            self::remove($dir);
        }
    }

    /**
     * The rest of the file must survive stripping intact — a greedy pattern that swallowed the trailing
     * `php_server` would produce a Caddyfile that parses and serves nothing.
     */
    public function test_stripping_leaves_the_rest_of_the_config_intact(): void
    {
        $dir = self::tempDir();

        try {
            self::publishTo($dir, withMercure: false);
            $caddyfile = self::caddyfile($dir);

            $this->assertStringContainsString('php_server', $caddyfile);
            $this->assertStringContainsString('max_threads auto', $caddyfile);
            $this->assertStringContainsString('trusted_proxies', $caddyfile);
            $this->assertStringContainsString('zone realtime_ip', $caddyfile);
        } finally {
            self::remove($dir);
        }
    }

    public function test_hub_is_published_when_opted_in(): void
    {
        $dir = self::tempDir();

        try {
            self::publishTo($dir, withMercure: true);
            $caddyfile = self::caddyfile($dir);

            $this->assertStringContainsString('mercure {', $caddyfile);
            $this->assertStringContainsString('transport local', $caddyfile);
            $this->assertStringContainsString('publisher_jwt', $caddyfile);
            $this->assertStringContainsString('subscriber_jwt', $caddyfile);
        } finally {
            self::remove($dir);
        }
    }

    /**
     * Anonymous subscribers must stay disabled. Every topic is per-subject, so an anonymous subscriber
     * has nothing it is entitled to receive, and enabling it would make the hub authorise by omission.
     */
    public function test_anonymous_subscribers_are_not_enabled(): void
    {
        $dir = self::tempDir();

        try {
            self::publishTo($dir, withMercure: true);

            $this->assertDoesNotMatchRegularExpression(
                '/^\s*anonymous\b/m',
                self::caddyfile($dir),
            );
        } finally {
            self::remove($dir);
        }
    }

    /**
     * A framework stub cannot guess an app's own origins, and guessing wrong means either a dead
     * notification bell or a hub accepting an origin nobody vetted.
     */
    public function test_cors_origins_has_no_baked_in_default(): void
    {
        $dir = self::tempDir();

        try {
            self::publishTo($dir, withMercure: true);

            $this->assertStringContainsString('cors_origins {$VORTOS_MERCURE_CORS_ORIGINS}', self::caddyfile($dir));
        } finally {
            self::remove($dir);
        }
    }

    /**
     * The secret must never carry a default. A hub signed with a value readable in the framework's
     * source would accept tokens anyone could forge, which is strictly worse than shipping no hub.
     */
    public function test_jwt_secret_has_no_baked_in_default(): void
    {
        $dir = self::tempDir();

        try {
            self::publishTo($dir, withMercure: true);
            $caddyfile = self::caddyfile($dir);

            $this->assertStringContainsString('publisher_jwt {$VORTOS_MERCURE_JWT_SECRET}', $caddyfile);
            $this->assertStringContainsString('subscriber_jwt {$VORTOS_MERCURE_JWT_SECRET}', $caddyfile);
            // A Caddy env placeholder with a default uses `{$NAME:default}` — there must be no colon.
            $this->assertStringNotContainsString('VORTOS_MERCURE_JWT_SECRET:', $caddyfile);
        } finally {
            self::remove($dir);
        }
    }
}
