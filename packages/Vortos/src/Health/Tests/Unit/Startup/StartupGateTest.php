<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Startup;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Startup\StartupGate;

final class StartupGateTest extends TestCase
{
    public function testInitiallyNotStarted(): void
    {
        $gate = new StartupGate();

        self::assertFalse($gate->isStarted());
    }

    public function testMarkStartedFlipsState(): void
    {
        $gate = new StartupGate();
        $gate->markStarted();

        self::assertTrue($gate->isStarted());
    }

    public function testMarkStartedIsIdempotent(): void
    {
        $gate = new StartupGate();
        $gate->markStarted();
        $gate->markStarted();

        self::assertTrue($gate->isStarted());
    }

    public function testResetClearsState(): void
    {
        $gate = new StartupGate();
        $gate->markStarted();
        $gate->reset();

        self::assertFalse($gate->isStarted());
    }
}
