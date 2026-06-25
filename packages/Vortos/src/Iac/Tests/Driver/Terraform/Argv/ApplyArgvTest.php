<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Driver\Terraform\Argv;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\Argv\ApplyArgv;

final class ApplyArgvTest extends TestCase
{
    public function test_build(): void
    {
        $argv = ApplyArgv::build('/usr/bin/tofu', '/tmp/plan.bin');
        $this->assertSame('/usr/bin/tofu', $argv[0]);
        $this->assertSame('apply', $argv[1]);
        $this->assertContains('-input=false', $argv);
        $this->assertContains('-no-color', $argv);
        $this->assertContains('-auto-approve', $argv);
        $this->assertContains('/tmp/plan.bin', $argv);
    }
}
