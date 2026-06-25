<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\Target\ActiveColor;

final class CurrentReleaseTest extends TestCase
{
    public function test_round_trips_through_array(): void
    {
        $release = new CurrentRelease(
            env: 'production',
            activeColor: ActiveColor::Blue,
            imageDigest: 'sha256:abc123',
            buildId: 'build-1',
            planHash: 'sha256:plan1',
            recordedAt: new \DateTimeImmutable('2026-06-23T10:00:00+00:00'),
            generation: 5,
        );

        $restored = CurrentRelease::fromArray($release->toArray());

        $this->assertSame('production', $restored->env);
        $this->assertSame(ActiveColor::Blue, $restored->activeColor);
        $this->assertSame('sha256:abc123', $restored->imageDigest);
        $this->assertSame('build-1', $restored->buildId);
        $this->assertSame('sha256:plan1', $restored->planHash);
        $this->assertSame(5, $restored->generation);
    }

    public function test_rejects_empty_env(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CurrentRelease(
            env: '',
            activeColor: ActiveColor::Blue,
            imageDigest: 'sha256:abc',
            buildId: 'b1',
            planHash: 'sha256:p1',
            recordedAt: new \DateTimeImmutable(),
            generation: 0,
        );
    }

    public function test_rejects_negative_generation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CurrentRelease(
            env: 'prod',
            activeColor: ActiveColor::Blue,
            imageDigest: 'sha256:abc',
            buildId: 'b1',
            planHash: 'sha256:p1',
            recordedAt: new \DateTimeImmutable(),
            generation: -1,
        );
    }

    public function test_with_color(): void
    {
        $release = new CurrentRelease(
            env: 'prod',
            activeColor: ActiveColor::Blue,
            imageDigest: 'sha256:abc',
            buildId: 'b1',
            planHash: 'sha256:p1',
            recordedAt: new \DateTimeImmutable(),
            generation: 1,
        );

        $flipped = $release->withColor(ActiveColor::Green);

        $this->assertSame(ActiveColor::Green, $flipped->activeColor);
        $this->assertSame('prod', $flipped->env);
        $this->assertSame(1, $flipped->generation);
    }
}
