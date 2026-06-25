<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Destination secrets (bot tokens, webhook URLs, SMTP creds) come only from
 * `${env:...}` read at use-time via {@see \Vortos\Alerts\Notifier\Driver\EnvLookup} —
 * never inlined. This asserts environment access is centralized to that one class
 * (plus the DI extension, which only ever reads var *names* from `$_ENV` for wiring
 * config, never secret values into a committed artifact).
 */
final class NoPlaintextSecretInArtifactTest extends TestCase
{
    public function test_env_access_is_centralized_to_env_lookup(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php') || str_contains($path, '/Tests/')) {
                continue;
            }
            if (str_ends_with($path, '/Notifier/Driver/EnvLookup.php') || str_contains($path, '/DependencyInjection/')) {
                continue; // EnvLookup itself, and the composition root (reads var names/flags only)
            }

            $contents = (string) file_get_contents($path);
            if (preg_match('/\$_ENV\s*\[|\$_SERVER\s*\[|\bgetenv\s*\(/', $contents) === 1) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations, 'raw environment access must go through EnvLookup, never inlined per-driver');
    }
}
