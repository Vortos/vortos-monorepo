<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Driver\Terraform\Argv;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\Argv\InitArgv;

final class InitArgvTest extends TestCase
{
    public function test_build(): void
    {
        $argv = InitArgv::build('/usr/bin/tofu');
        $this->assertSame(['/usr/bin/tofu', 'init', '-input=false', '-no-color'], $argv);
    }

    public function test_no_shell_metacharacters(): void
    {
        $argv = InitArgv::build('/usr/bin/tofu');
        foreach ($argv as $arg) {
            $this->assertStringNotContainsString('|', $arg);
            $this->assertStringNotContainsString(';', $arg);
            $this->assertStringNotContainsString('`', $arg);
            $this->assertStringNotContainsString('$', $arg);
        }
    }
}
