<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Build\RegistryBaseImageDigestResolver;

final class RegistryBaseImageDigestResolverTest extends TestCase
{
    public function test_script_resolves_and_exports_digest(): void
    {
        $script = (new RegistryBaseImageDigestResolver())->generate('docker/Dockerfile');

        $this->assertStringContainsString('DOCKERFILE="docker/Dockerfile"', $script);
        $this->assertStringContainsString('imagetools inspect', $script);
        $this->assertStringContainsString('BASE_IMAGE_DIGEST=$DIGEST', $script);
        $this->assertStringContainsString('$GITHUB_ENV', $script);
    }

    public function test_script_is_non_fatal_and_warns_when_unresolvable(): void
    {
        $script = (new RegistryBaseImageDigestResolver())->generate('docker/Dockerfile');

        // Every failure branch warns and exits 0 (never fails the build).
        $this->assertStringContainsString('::warning::', $script);
        $this->assertStringContainsString('exit 0', $script);
        $this->assertStringNotContainsString('exit 1', $script);
    }

    public function test_follows_stage_alias_to_external_base_of_final_stage(): void
    {
        // R8-8 / B2: a final `FROM base` must resolve to base's external image, not the alias "base".
        $dockerfile = <<<DF
            FROM golang:1.22 AS builder
            RUN build
            FROM debian:bookworm-slim AS base
            RUN setup
            FROM base
            COPY --from=builder /app /app
            DF;

        $resolved = $this->runResolution($dockerfile);

        $this->assertSame('debian:bookworm-slim', $resolved);
    }

    public function test_single_stage_resolves_its_own_from(): void
    {
        $resolved = $this->runResolution("FROM node:20-alpine\nRUN npm ci\n");

        $this->assertSame('node:20-alpine', $resolved);
    }

    /**
     * Execute the generated script with a stub `docker` on PATH that echoes the ref it was asked to
     * inspect, so we can assert exactly which base reference resolution settled on.
     */
    private function runResolution(string $dockerfile): string
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('shell harness is POSIX-only');
        }

        $dir = sys_get_temp_dir() . '/vortos_baseimg_' . uniqid('', true);
        mkdir($dir . '/bin', 0755, true);
        file_put_contents($dir . '/Dockerfile', $dockerfile);

        // Stub docker: record the ref it was asked to inspect (arg 4) to $INSPECT_LOG, then print a
        // fake digest on stdout. A file is used because the script redirects docker's stderr to
        // /dev/null, so a stderr marker would be swallowed.
        file_put_contents($dir . '/bin/docker', "#!/bin/sh\necho \"\$4\" > \"\$INSPECT_LOG\"\necho '\"sha256:deadbeef\"'\n");
        chmod($dir . '/bin/docker', 0755);

        $script = (new RegistryBaseImageDigestResolver())->generate($dir . '/Dockerfile');

        $cmd = sprintf(
            'PATH=%s:$PATH GITHUB_ENV=%s INSPECT_LOG=%s bash -c %s',
            escapeshellarg($dir . '/bin'),
            escapeshellarg($dir . '/gh_env'),
            escapeshellarg($dir . '/inspect_log'),
            escapeshellarg($script),
        );
        exec($cmd);

        $inspected = trim((string) @file_get_contents($dir . '/inspect_log'));
        $this->cleanup($dir);

        return $inspected;
    }

    private function cleanup(string $dir): void
    {
        foreach (['/bin/docker', '/Dockerfile', '/gh_env', '/inspect_log'] as $f) {
            @unlink($dir . $f);
        }
        @rmdir($dir . '/bin');
        @rmdir($dir);
    }
}
