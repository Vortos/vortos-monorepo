<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Heartbeat;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Heartbeat\HeartbeatPing;
use Vortos\Observability\Heartbeat\HeartbeatStatus;
use Vortos\Observability\Heartbeat\HttpHeartbeatEmitter;

final class HttpHeartbeatEmitterTest extends TestCase
{
    private const ENV = 'OBSERVABILITY_HEARTBEAT_URL_TEST';

    protected function tearDown(): void
    {
        unset($_ENV[self::ENV], $_SERVER[self::ENV]);
    }

    public function test_returns_false_when_not_configured(): void
    {
        $emitter = new HttpHeartbeatEmitter(self::ENV);

        self::assertFalse($emitter->emit(HeartbeatPing::create('app', HeartbeatStatus::Success)));
    }

    public function test_does_not_throw_on_unreachable_host(): void
    {
        // RFC 5737 TEST-NET-1, never routable; with a tight timeout the call fails fast.
        $_ENV[self::ENV] = 'http://192.0.2.1:9/heartbeat';
        $emitter = new HttpHeartbeatEmitter(self::ENV, connectTimeoutSeconds: 1, totalTimeoutSeconds: 1);

        self::assertFalse($emitter->emit(HeartbeatPing::create('app', HeartbeatStatus::Success)));
    }
}
