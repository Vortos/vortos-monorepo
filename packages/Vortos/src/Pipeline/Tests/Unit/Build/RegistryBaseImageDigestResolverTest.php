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
}
