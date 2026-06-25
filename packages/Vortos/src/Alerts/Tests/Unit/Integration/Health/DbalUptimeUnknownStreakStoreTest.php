<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Integration\Health;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Integration\Health\DbalUptimeUnknownStreakStore;

final class DbalUptimeUnknownStreakStoreTest extends TestCase
{
    private Connection $connection;
    private DbalUptimeUnknownStreakStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE alerts_uptime_streaks (
                monitor_id VARCHAR(255) PRIMARY KEY,
                streak INTEGER NOT NULL
            )',
        );
        $this->store = new DbalUptimeUnknownStreakStore($this->connection, 'alerts_uptime_streaks');
    }

    public function testIncrementStartsAtOne(): void
    {
        self::assertSame(1, $this->store->increment('m1'));
    }

    public function testIncrementAccumulatesAcrossCalls(): void
    {
        $this->store->increment('m1');
        $this->store->increment('m1');

        self::assertSame(3, $this->store->increment('m1'));
    }

    public function testResetDeletesTheRow(): void
    {
        $this->store->increment('m1');
        $this->store->reset('m1');

        self::assertSame(1, $this->store->increment('m1'));

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM alerts_uptime_streaks');
        self::assertSame(1, (int) $count);
    }

    public function testMonitorsAreIndependentRows(): void
    {
        $this->store->increment('m1');
        $this->store->increment('m1');
        $this->store->increment('m2');

        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT streak FROM alerts_uptime_streaks WHERE monitor_id = :id',
            ['id' => 'm1'],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT streak FROM alerts_uptime_streaks WHERE monitor_id = :id',
            ['id' => 'm2'],
        ));
    }
}
