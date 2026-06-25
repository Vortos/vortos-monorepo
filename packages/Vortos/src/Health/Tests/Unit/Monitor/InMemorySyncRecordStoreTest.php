<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Monitor;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Monitor\InMemorySyncRecordStore;

final class InMemorySyncRecordStoreTest extends TestCase
{
    public function testUnknownJourneyHasNoLastHash(): void
    {
        self::assertNull((new InMemorySyncRecordStore())->lastHash('prod', 'login-fetch'));
    }

    public function testRecordThenLastHashRoundTrips(): void
    {
        $store = new InMemorySyncRecordStore();
        $store->record('prod', 'login-fetch', 'hash-1', 'mon-1');

        self::assertSame('hash-1', $store->lastHash('prod', 'login-fetch'));
        self::assertSame('mon-1', $store->monitorId('prod', 'login-fetch'));
    }

    public function testDistinctEnvironmentsAreIsolated(): void
    {
        $store = new InMemorySyncRecordStore();
        $store->record('prod', 'login-fetch', 'hash-prod', 'mon-prod');
        $store->record('staging', 'login-fetch', 'hash-staging', 'mon-staging');

        self::assertSame('hash-prod', $store->lastHash('prod', 'login-fetch'));
        self::assertSame('hash-staging', $store->lastHash('staging', 'login-fetch'));
    }

    public function testRecordOverwritesPreviousHash(): void
    {
        $store = new InMemorySyncRecordStore();
        $store->record('prod', 'login-fetch', 'hash-1', 'mon-1');
        $store->record('prod', 'login-fetch', 'hash-2', 'mon-1');

        self::assertSame('hash-2', $store->lastHash('prod', 'login-fetch'));
    }
}
