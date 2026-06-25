<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Heartbeat;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Observability\Heartbeat\HeartbeatPing;
use Vortos\Observability\Heartbeat\HeartbeatStatus;

final class HeartbeatPingTest extends TestCase
{
    public function test_creates_with_trimmed_key(): void
    {
        $ping = HeartbeatPing::create('  app  ', HeartbeatStatus::Success);

        self::assertSame('app', $ping->monitorKey);
        self::assertSame(HeartbeatStatus::Success, $ping->status);
    }

    public function test_rejects_empty_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HeartbeatPing::create('   ', HeartbeatStatus::Start);
    }

    public function test_truncates_long_note(): void
    {
        $ping = HeartbeatPing::create('app', HeartbeatStatus::Fail, str_repeat('n', 1000));

        self::assertLessThanOrEqual(500, strlen((string) $ping->note));
    }

    public function test_status_url_suffixes(): void
    {
        self::assertSame('/start', HeartbeatStatus::Start->urlSuffix());
        self::assertSame('', HeartbeatStatus::Success->urlSuffix());
        self::assertSame('/fail', HeartbeatStatus::Fail->urlSuffix());
    }
}
