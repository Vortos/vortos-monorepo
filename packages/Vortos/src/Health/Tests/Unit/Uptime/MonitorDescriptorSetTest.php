<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\MonitorDescriptorSet;
use Vortos\Health\Uptime\SyntheticJourney;

final class MonitorDescriptorSetTest extends TestCase
{
    private function descriptor(string $key): MonitorDescriptor
    {
        return new MonitorDescriptor($key, 'name', new SyntheticJourney('j', [
            new JourneyStep('POST', '/login', 200),
            new JourneyStep('GET', '/me', 200, bodyContains: 'ok'),
        ]));
    }

    public function testEmptyByDefault(): void
    {
        self::assertSame([], (new MonitorDescriptorSet())->all());
    }

    public function testAddThenGetRoundTrips(): void
    {
        $set = new MonitorDescriptorSet([$this->descriptor('a')]);

        self::assertTrue($set->has('a'));
        self::assertSame('a', $set->get('a')->key);
    }

    public function testRejectsDuplicateKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonitorDescriptorSet([$this->descriptor('a'), $this->descriptor('a')]);
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MonitorDescriptorSet())->get('missing');
    }
}
