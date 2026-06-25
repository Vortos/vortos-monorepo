<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Driver\GitHubActions;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\ArchAssertionScript;
use Vortos\Release\Manifest\Arch;

final class ArchAssertionScriptTest extends TestCase
{
    public function test_arm64_snippet(): void
    {
        $script = new ArchAssertionScript();
        $output = $script->generate('ghcr.io/org/app@sha256:abc123', Arch::Arm64);

        $this->assertStringContainsString('docker manifest inspect', $output);
        $this->assertStringContainsString('arm64', $output);
        $this->assertStringContainsString('linux', $output);
        $this->assertStringContainsString('exit 1', $output);
        $this->assertStringContainsString('ghcr.io/org/app@sha256:abc123', $output);
    }

    public function test_amd64_snippet(): void
    {
        $script = new ArchAssertionScript();
        $output = $script->generate('docker.io/lib/nginx@sha256:def456', Arch::Amd64);

        $this->assertStringContainsString('amd64', $output);
        $this->assertStringContainsString('linux', $output);
        $this->assertStringContainsString('exit 1', $output);
    }

    public function test_mismatch_exit_code(): void
    {
        $script = new ArchAssertionScript();
        $output = $script->generate('image@sha256:abc', Arch::Arm64);

        $this->assertStringContainsString('Architecture mismatch', $output);
        $this->assertStringContainsString('exit 1', $output);
    }

    public function test_success_message(): void
    {
        $script = new ArchAssertionScript();
        $output = $script->generate('image@sha256:abc', Arch::Arm64);

        $this->assertStringContainsString('Architecture verified', $output);
    }
}
