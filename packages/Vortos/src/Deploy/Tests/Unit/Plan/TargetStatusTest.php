<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Target\TargetStatus;

final class TargetStatusTest extends TestCase
{
    public function test_valid_construction(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $status = new TargetStatus(ActiveColor::Blue, $digest, 'healthy', new \DateTimeImmutable());

        self::assertSame(ActiveColor::Blue, $status->color);
        self::assertSame($digest, $status->imageDigest);
        self::assertSame('healthy', $status->healthStatus);
    }

    public function test_empty_digest_allowed(): void
    {
        $status = new TargetStatus(ActiveColor::None, '', 'unknown', new \DateTimeImmutable());
        self::assertSame('', $status->imageDigest);
    }

    public function test_invalid_digest_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TargetStatus(ActiveColor::Blue, 'invalid', 'healthy', new \DateTimeImmutable());
    }

    public function test_to_array(): void
    {
        $digest = 'sha256:' . str_repeat('b', 64);
        $now = new \DateTimeImmutable('2026-06-23T12:00:00+00:00');
        $status = new TargetStatus(ActiveColor::Green, $digest, 'healthy', $now);

        $arr = $status->toArray();
        self::assertSame('green', $arr['color']);
        self::assertSame($digest, $arr['image_digest']);
        self::assertSame('healthy', $arr['health_status']);
    }
}
