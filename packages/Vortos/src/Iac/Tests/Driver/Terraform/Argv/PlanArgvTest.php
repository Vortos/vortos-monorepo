<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Driver\Terraform\Argv;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\Argv\PlanArgv;

final class PlanArgvTest extends TestCase
{
    public function test_build_default(): void
    {
        $argv = PlanArgv::build('/usr/bin/tofu', '/tmp/plan.bin', 10, 60);
        $this->assertSame('/usr/bin/tofu', $argv[0]);
        $this->assertSame('plan', $argv[1]);
        $this->assertContains('-input=false', $argv);
        $this->assertContains('-no-color', $argv);
        $this->assertContains('-out=/tmp/plan.bin', $argv);
        $this->assertContains('-lock-timeout=60s', $argv);
        $this->assertContains('-parallelism=10', $argv);
        $this->assertNotContains('-destroy', $argv);
        $this->assertNotContains('-refresh-only', $argv);
    }

    public function test_build_with_destroy(): void
    {
        $argv = PlanArgv::build('/usr/bin/tofu', '/tmp/plan.bin', 5, 30, destroy: true);
        $this->assertContains('-destroy', $argv);
    }

    public function test_build_with_refresh_only(): void
    {
        $argv = PlanArgv::build('/usr/bin/tofu', '/tmp/plan.bin', 5, 30, refreshOnly: true);
        $this->assertContains('-refresh-only', $argv);
    }

    public function test_parallelism_and_lock_timeout(): void
    {
        $argv = PlanArgv::build('/usr/bin/tofu', '/tmp/p.bin', 20, 120);
        $this->assertContains('-parallelism=20', $argv);
        $this->assertContains('-lock-timeout=120s', $argv);
    }
}
