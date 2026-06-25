<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\Check\TargetArchCheck;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator;
use Vortos\Deploy\Tests\Fixtures\NoArchConstraintTarget;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Release\Manifest\Arch;

final class TargetArchCheckTest extends TestCase
{
    use PreflightTestFactory;

    public function test_aligned_passes(): void
    {
        // FakeDeployTarget declares target_arch=linux/arm64; definition + manifest agree.
        $finding = (new TargetArchCheck($this->targetRegistry()))->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_definition_arch_differs_from_manifest_fails(): void
    {
        $ctx = $this->context(
            $this->definition(arch: Arch::Amd64),
            $this->manifest(arch: Arch::Arm64),
        );

        $finding = (new TargetArchCheck($this->targetRegistry()))->check($ctx);

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('does not match the build manifest', $finding->summary);
    }

    public function test_manifest_arch_differs_from_target_constraint_fails(): void
    {
        // Definition + manifest agree on amd64, but the target constrains arm64.
        $ctx = $this->context(
            $this->definition(arch: Arch::Amd64),
            $this->manifest(arch: Arch::Amd64),
        );

        $finding = (new TargetArchCheck($this->targetRegistry()))->check($ctx);

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('target arch constraint', $finding->summary);
    }

    public function test_no_target_constraint_skips(): void
    {
        $registry = new DeployTargetRegistry(new InMemoryServiceLocator([
            'no-arch' => new NoArchConstraintTarget(),
        ]));

        $ctx = $this->context($this->definition(host: 'no-arch', arch: Arch::Arm64), $this->manifest(arch: Arch::Arm64));

        $finding = (new TargetArchCheck($registry))->check($ctx);

        $this->assertSame(PreflightStatus::Skip, $finding->status);
    }

    public function test_unregistered_target_fails(): void
    {
        $finding = (new TargetArchCheck($this->targetRegistry()))
            ->check($this->context($this->definition(host: 'ghost')));

        $this->assertSame(PreflightStatus::Fail, $finding->status);
    }

    public function test_skip_counts_as_clear(): void
    {
        $registry = new DeployTargetRegistry(new InMemoryServiceLocator([
            'no-arch' => new NoArchConstraintTarget(),
        ]));
        $ctx = $this->context($this->definition(host: 'no-arch'), $this->manifest());

        $finding = (new TargetArchCheck($registry))->check($ctx);

        $this->assertTrue($finding->status->isClear());
    }
}
