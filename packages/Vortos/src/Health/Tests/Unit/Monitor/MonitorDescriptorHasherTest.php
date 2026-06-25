<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Monitor;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Monitor\MonitorDescriptorHasher;
use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\SyntheticJourney;

final class MonitorDescriptorHasherTest extends TestCase
{
    private function descriptor(int $intervalSeconds = 60): MonitorDescriptor
    {
        return new MonitorDescriptor('key', 'name', new SyntheticJourney('j', [
            new JourneyStep('POST', '/login', 200),
            new JourneyStep('GET', '/me', 200, bodyContains: 'ok'),
        ]), intervalSeconds: $intervalSeconds);
    }

    public function testSameDescriptorProducesSameHash(): void
    {
        $hasher = new MonitorDescriptorHasher();

        self::assertSame($hasher->hash($this->descriptor()), $hasher->hash($this->descriptor()));
    }

    public function testChangedFieldProducesDifferentHash(): void
    {
        $hasher = new MonitorDescriptorHasher();

        self::assertNotSame($hasher->hash($this->descriptor(60)), $hasher->hash($this->descriptor(120)));
    }

    public function testHashIsAStableLengthHexString(): void
    {
        $hash = (new MonitorDescriptorHasher())->hash($this->descriptor());

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }
}
