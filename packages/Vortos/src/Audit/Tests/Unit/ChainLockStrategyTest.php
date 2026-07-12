<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Audit\Storage\Dbal\Lock\PgAdvisoryChainLock;
use Vortos\Audit\Storage\Dbal\Lock\RowChainLock;

final class ChainLockStrategyTest extends TestCase
{
    public function test_pg_advisory_lock_key_is_deterministic_and_distinct_per_chain(): void
    {
        self::assertSame(
            PgAdvisoryChainLock::lockKey('platform'),
            PgAdvisoryChainLock::lockKey('platform'),
            'the same chain always maps to the same lock key',
        );
        self::assertNotSame(
            PgAdvisoryChainLock::lockKey('tenant:a'),
            PgAdvisoryChainLock::lockKey('tenant:b'),
            'different chains map to different lock keys',
        );
    }

    public function test_pg_advisory_lock_issues_the_advisory_statement(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('pg_advisory_xact_lock'),
                ['k' => PgAdvisoryChainLock::lockKey('platform')],
            );

        (new PgAdvisoryChainLock())->acquire($conn, 'platform');
    }

    public function test_row_lock_locks_existing_head_row_without_inserting(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::once())->method('fetchOne')->willReturn('tenant:1');
        $conn->expects(self::never())->method('insert');

        (new RowChainLock())->acquire($conn, 'tenant:1');
    }

    public function test_row_lock_creates_then_locks_the_head_row_when_missing(): void
    {
        $conn = $this->createMock(Connection::class);
        // First select finds nothing, the post-insert select locks the new row.
        $conn->method('fetchOne')->willReturnOnConsecutiveCalls(false, 'tenant:9');
        $conn->expects(self::once())->method('insert')
            ->with('vortos_audit_chain_heads', ['chain_key' => 'tenant:9']);

        (new RowChainLock('vortos_audit_chain_heads'))->acquire($conn, 'tenant:9');
    }
}
