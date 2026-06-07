<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Command\TailConsumerCommand;

final class TailConsumerCommandTest extends TestCase
{
    private function makeCommand(?\Redis $redis = null): TailConsumerCommand
    {
        return new TailConsumerCommand($redis);
    }

    public function test_command_name(): void
    {
        $this->assertSame('vortos:consumer:tail', $this->makeCommand()->getName());
    }

    public function test_has_consumer_argument(): void
    {
        $this->assertTrue($this->makeCommand()->getDefinition()->hasArgument('consumer'));
    }

    public function test_fails_when_redis_is_null(): void
    {
        $tester = new CommandTester($this->makeCommand(null));
        $tester->execute(['consumer' => 'user-consumer']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Redis', $tester->getDisplay());
    }
}
