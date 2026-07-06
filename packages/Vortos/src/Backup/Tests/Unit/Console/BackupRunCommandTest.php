<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Backup\Console\BackupRunCommand;
use Vortos\Backup\Domain\EngineResolver;
use Vortos\Backup\Domain\Exception\EngineNotConfiguredException;
use Vortos\Backup\Service\BackupRunner;

final class BackupRunCommandTest extends TestCase
{
    public function test_fails_closed_when_no_engine_flag_and_no_default(): void
    {
        // The runner must never be reached — resolution fails first. Instantiated without its
        // constructor so any accidental call would fatal, proving it stayed untouched.
        $runner = (new \ReflectionClass(BackupRunner::class))->newInstanceWithoutConstructor();
        $command = new BackupRunCommand($runner, new EngineResolver(null));
        $tester = new CommandTester($command);

        $this->expectException(EngineNotConfiguredException::class);
        $tester->execute([]);
    }

    public function test_unknown_engine_flag_fails_closed(): void
    {
        $runner = (new \ReflectionClass(BackupRunner::class))->newInstanceWithoutConstructor();
        $command = new BackupRunCommand($runner, new EngineResolver(null));
        $tester = new CommandTester($command);

        $this->expectException(\Vortos\Backup\Domain\Exception\UnknownEngineException::class);
        $tester->execute(['--engine' => 'redis']);
    }
}
