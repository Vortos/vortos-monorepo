<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Segment;
use Vortos\FeatureFlags\SegmentRegistry;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;

final class SegmentRegistryTest extends TestCase
{
    public function test_bulk_loads_once_for_many_resolves(): void
    {
        // The N×M guard: 20 lookups, ONE findAll().
        $storage = $this->createMock(SegmentStorageInterface::class);
        $storage->expects($this->once())->method('findAll')->willReturn([
            $this->segment('beta'),
            $this->segment('vip'),
        ]);

        $registry = new SegmentRegistry($storage);

        for ($i = 0; $i < 20; $i++) {
            $registry->resolve('beta');
            $registry->resolve('vip');
        }
    }

    public function test_resolves_by_name(): void
    {
        $storage = $this->createMock(SegmentStorageInterface::class);
        $storage->method('findAll')->willReturn([$this->segment('beta')]);

        $registry = new SegmentRegistry($storage);
        $this->assertSame('beta', $registry->resolve('beta')?->name);
    }

    public function test_unknown_segment_resolves_to_null(): void
    {
        $storage = $this->createMock(SegmentStorageInterface::class);
        $storage->method('findAll')->willReturn([]);

        $registry = new SegmentRegistry($storage);
        $this->assertNull($registry->resolve('nope'));
    }

    public function test_reset_reloads(): void
    {
        $storage = $this->createMock(SegmentStorageInterface::class);
        $storage->expects($this->exactly(2))->method('findAll')->willReturn([]);

        $registry = new SegmentRegistry($storage);
        $registry->resolve('x');
        $registry->reset();
        $registry->resolve('x');
    }

    private function segment(string $name): Segment
    {
        $now = new \DateTimeImmutable('2024-01-01');
        return new Segment('id-' . $name, $name, '', [new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 100)], $now, $now);
    }
}
