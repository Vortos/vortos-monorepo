<?php

declare(strict_types=1);

namespace Vortos\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Cache\Command\CacheWarmupCommand;
use Vortos\Cache\Contract\CacheWarmerInterface;

final class CacheWarmupCommandTest extends TestCase
{
    public function test_no_warmers_is_silent_at_normal_verbosity(): void
    {
        // R8-12 (C1): "no warmers" is a valid config; it must not print on every invocation.
        $tester = new CommandTester(new CacheWarmupCommand([]));
        $tester->execute([]);

        $this->assertStringNotContainsString('No cache warmers registered', $tester->getDisplay());
    }

    public function test_no_warmers_is_reported_in_verbose_mode(): void
    {
        $tester = new CommandTester(new CacheWarmupCommand([]));
        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertStringContainsString('No cache warmers registered', $tester->getDisplay());
    }

    public function test_ran_warmers_are_reported(): void
    {
        $warmer = new class implements CacheWarmerInterface {
            public bool $warmed = false;
            public function warmUp(): void { $this->warmed = true; }
        };

        $tester = new CommandTester(new CacheWarmupCommand([$warmer]));
        $tester->execute([]);

        $this->assertTrue($warmer->warmed);
        $this->assertStringContainsString('1 warmer(s) ran successfully', $tester->getDisplay());
    }
}
