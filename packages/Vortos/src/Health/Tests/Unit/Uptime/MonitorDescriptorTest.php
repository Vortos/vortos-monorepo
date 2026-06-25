<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\SyntheticJourney;

final class MonitorDescriptorTest extends TestCase
{
    private function journey(): SyntheticJourney
    {
        return new SyntheticJourney('login-fetch', [
            new JourneyStep('POST', '/login', 200, extractAs: 'token', extractJsonPath: 'data.token'),
            new JourneyStep('GET', '/me', 200, bodyContains: '"email"'),
        ]);
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonitorDescriptor('', 'name', $this->journey());
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonitorDescriptor('key', '', $this->journey());
    }

    public function testRejectsZeroInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonitorDescriptor('key', 'name', $this->journey(), intervalSeconds: 0);
    }

    public function testRejectsNegativeInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonitorDescriptor('key', 'name', $this->journey(), intervalSeconds: -5);
    }

    public function testRejectsEmptyRegionEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonitorDescriptor('key', 'name', $this->journey(), regions: ['']);
    }

    public function testRejectsZeroResponseTimeSlo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonitorDescriptor('key', 'name', $this->journey(), responseTimeSloMs: 0);
    }

    public function testDefaultsAreSane(): void
    {
        $descriptor = new MonitorDescriptor('key', 'name', $this->journey());

        self::assertSame(60, $descriptor->intervalSeconds);
        self::assertSame([], $descriptor->regions);
        self::assertNull($descriptor->responseTimeSloMs);
    }

    public function testAcceptsMultiRegionAndSlo(): void
    {
        $descriptor = new MonitorDescriptor(
            'key',
            'name',
            $this->journey(),
            intervalSeconds: 30,
            regions: ['eu-west', 'us-east'],
            responseTimeSloMs: 500,
        );

        self::assertSame(['eu-west', 'us-east'], $descriptor->regions);
        self::assertSame(500, $descriptor->responseTimeSloMs);
    }
}
