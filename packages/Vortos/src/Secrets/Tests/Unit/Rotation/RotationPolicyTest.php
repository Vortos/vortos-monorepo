<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Rotation;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Rotation\RotationException;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Value\SecretVersion;
use Vortos\Secrets\Value\SecretVersionState;

final class RotationPolicyTest extends TestCase
{
    public function test_zero_interval_rejected(): void
    {
        $this->expectException(RotationException::class);
        new RotationPolicy(0, 60, 3600);
    }

    public function test_negative_grace_rejected(): void
    {
        $this->expectException(RotationException::class);
        new RotationPolicy(3600, -1, 7200);
    }

    public function test_zero_max_age_rejected(): void
    {
        $this->expectException(RotationException::class);
        new RotationPolicy(3600, 60, 0);
    }

    public function test_max_age_below_interval_rejected(): void
    {
        $this->expectException(RotationException::class);
        new RotationPolicy(3600, 60, 1800);
    }

    public function test_zero_grace_period_is_allowed(): void
    {
        $policy = new RotationPolicy(3600, 0, 7200);

        self::assertSame(0, $policy->gracePeriodSeconds);
    }

    public function test_is_due_at_exact_boundary(): void
    {
        $policy = new RotationPolicy(3600, 60, 7200);
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $version = new SecretVersion('v1', $createdAt, SecretVersionState::Active);

        $exactlyDue = $createdAt->modify('+3600 seconds');
        self::assertTrue($policy->isDue($version, $exactlyDue));

        $oneSecondBefore = $createdAt->modify('+3599 seconds');
        self::assertFalse($policy->isDue($version, $oneSecondBefore));
    }

    public function test_is_within_grace_at_boundaries(): void
    {
        $policy = new RotationPolicy(3600, 60, 7200);
        $supersededAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertTrue($policy->isWithinGrace($supersededAt, $supersededAt));
        self::assertTrue($policy->isWithinGrace($supersededAt, $supersededAt->modify('+59 seconds')));
        self::assertFalse($policy->isWithinGrace($supersededAt, $supersededAt->modify('+60 seconds')));
        self::assertFalse($policy->isWithinGrace($supersededAt, $supersededAt->modify('-1 seconds')));
    }

    public function test_is_within_grace_with_zero_grace_period_is_always_false_after_now(): void
    {
        $policy = new RotationPolicy(3600, 0, 7200);
        $supersededAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertFalse($policy->isWithinGrace($supersededAt, $supersededAt));
    }

    public function test_is_past_max_age(): void
    {
        $policy = new RotationPolicy(3600, 60, 7200);
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $version = new SecretVersion('v1', $createdAt, SecretVersionState::Active);

        self::assertFalse($policy->isPastMaxAge($version, $createdAt->modify('+7200 seconds')));
        self::assertTrue($policy->isPastMaxAge($version, $createdAt->modify('+7201 seconds')));
    }
}
