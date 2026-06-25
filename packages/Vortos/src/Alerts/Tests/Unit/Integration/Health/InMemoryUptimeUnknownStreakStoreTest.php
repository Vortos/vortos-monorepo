<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Integration\Health;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Integration\Health\InMemoryUptimeUnknownStreakStore;

final class InMemoryUptimeUnknownStreakStoreTest extends TestCase
{
    public function testIncrementStartsAtOne(): void
    {
        self::assertSame(1, (new InMemoryUptimeUnknownStreakStore())->increment('m1'));
    }

    public function testIncrementAccumulates(): void
    {
        $store = new InMemoryUptimeUnknownStreakStore();
        $store->increment('m1');
        $store->increment('m1');

        self::assertSame(3, $store->increment('m1'));
    }

    public function testResetClearsTheStreak(): void
    {
        $store = new InMemoryUptimeUnknownStreakStore();
        $store->increment('m1');
        $store->increment('m1');
        $store->reset('m1');

        self::assertSame(1, $store->increment('m1'));
    }

    public function testMonitorsAreIndependent(): void
    {
        $store = new InMemoryUptimeUnknownStreakStore();
        $store->increment('m1');
        $store->increment('m1');

        self::assertSame(1, $store->increment('m2'));
    }
}
