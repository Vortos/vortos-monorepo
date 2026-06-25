<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Monitor;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Monitor\DbalSyncRecordStore;

final class DbalSyncRecordStoreTest extends TestCase
{
    private Connection $connection;
    private DbalSyncRecordStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE uptime_sync (
                env VARCHAR(64) NOT NULL,
                journey_key VARCHAR(255) NOT NULL,
                payload_hash VARCHAR(64) NOT NULL,
                monitor_id VARCHAR(255) NOT NULL,
                applied_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (env, journey_key)
            )',
        );
        $this->store = new DbalSyncRecordStore($this->connection, 'uptime_sync');
    }

    public function testUnknownJourneyHasNoLastHash(): void
    {
        self::assertNull($this->store->lastHash('prod', 'login-fetch'));
    }

    public function testRecordThenLastHashRoundTrips(): void
    {
        $this->store->record('prod', 'login-fetch', 'hash-1', 'mon-1');

        self::assertSame('hash-1', $this->store->lastHash('prod', 'login-fetch'));
    }

    public function testSecondRecordUpdatesExistingRowRatherThanInserting(): void
    {
        $this->store->record('prod', 'login-fetch', 'hash-1', 'mon-1');
        $this->store->record('prod', 'login-fetch', 'hash-2', 'mon-1');

        self::assertSame('hash-2', $this->store->lastHash('prod', 'login-fetch'));

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM uptime_sync');
        self::assertSame(1, (int) $count);
    }

    public function testDistinctJourneyKeysAreIndependentRows(): void
    {
        $this->store->record('prod', 'login-fetch', 'hash-a', 'mon-a');
        $this->store->record('prod', 'status-page', 'hash-b', 'mon-b');

        self::assertSame('hash-a', $this->store->lastHash('prod', 'login-fetch'));
        self::assertSame('hash-b', $this->store->lastHash('prod', 'status-page'));
    }
}
