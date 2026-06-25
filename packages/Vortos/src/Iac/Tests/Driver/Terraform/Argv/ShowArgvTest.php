<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Driver\Terraform\Argv;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\Argv\ShowArgv;

final class ShowArgvTest extends TestCase
{
    public function test_build(): void
    {
        $argv = ShowArgv::build('/usr/bin/tofu', '/tmp/plan.bin');
        $this->assertSame(['/usr/bin/tofu', 'show', '-json', '-no-color', '/tmp/plan.bin'], $argv);
    }
}
