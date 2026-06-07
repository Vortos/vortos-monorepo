<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Vortos\PersistenceOrm\Transaction\OrmUnitOfWork;

final class OrmUnitOfWorkTest extends TestCase
{
    private function makeUow(Connection $conn, bool $emOpen = true): OrmUnitOfWork
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('isOpen')->willReturn($emOpen);

        return new OrmUnitOfWork($em);
    }

    private function makeConn(): Connection
    {
        $conn = $this->createMock(Connection::class);
        // SELECT 1 ping succeeds by default
        $conn->method('executeQuery');
        return $conn;
    }

    public function test_run_calls_begin_and_commit_on_success(): void
    {
        $conn = $this->makeConn();
        $conn->expects($this->once())->method('beginTransaction');
        $conn->expects($this->once())->method('commit');
        $conn->expects($this->never())->method('rollBack');

        $this->makeUow($conn)->run(function () {});
    }

    public function test_run_calls_rollback_on_exception(): void
    {
        $conn = $this->makeConn();
        $conn->expects($this->once())->method('beginTransaction');
        $conn->expects($this->never())->method('commit');
        $conn->expects($this->once())->method('rollBack');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->makeUow($conn)->run(function () { throw new \RuntimeException('boom'); });
    }

    public function test_run_returns_value_from_work(): void
    {
        $conn = $this->makeConn();
        $conn->method('beginTransaction');
        $conn->method('commit');

        $result = $this->makeUow($conn)->run(fn() => 42);

        $this->assertSame(42, $result);
    }

    public function test_run_returns_null_when_work_returns_nothing(): void
    {
        $conn = $this->makeConn();
        $conn->method('beginTransaction');
        $conn->method('commit');

        $result = $this->makeUow($conn)->run(function () {});

        $this->assertNull($result);
    }

    public function test_run_flushes_em_before_commit(): void
    {
        $conn = $this->makeConn();
        $conn->method('beginTransaction');
        $conn->method('commit');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('isOpen')->willReturn(true);
        $em->expects($this->once())->method('flush');

        (new OrmUnitOfWork($em))->run(function () {});
    }

    public function test_is_active_returns_true_when_transaction_open(): void
    {
        $conn = $this->makeConn();
        $conn->method('isTransactionActive')->willReturn(true);

        $this->assertTrue($this->makeUow($conn)->isActive());
    }

    public function test_is_active_returns_false_when_no_transaction(): void
    {
        $conn = $this->makeConn();
        $conn->method('isTransactionActive')->willReturn(false);

        $this->assertFalse($this->makeUow($conn)->isActive());
    }

    public function test_reset_clears_identity_map(): void
    {
        $conn = $this->makeConn();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('isOpen')->willReturn(true);
        $em->expects($this->once())->method('clear');

        (new OrmUnitOfWork($em))->reset();
    }

    public function test_reset_closes_connection_and_clears_when_em_is_closed(): void
    {
        $conn = $this->makeConn();
        $conn->expects($this->once())->method('close');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('isOpen')->willReturn(false);
        $em->expects($this->once())->method('clear');

        (new OrmUnitOfWork($em))->reset();
    }

    public function test_stale_connection_is_closed_before_transaction(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willThrowException(new \Exception('Connection lost'));
        $conn->expects($this->once())->method('close');
        $conn->method('beginTransaction');
        $conn->method('commit');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('isOpen')->willReturn(true);

        (new OrmUnitOfWork($em))->run(function () {});
    }

    public function test_exception_is_rethrown_after_rollback(): void
    {
        $conn = $this->makeConn();
        $conn->method('beginTransaction');
        $conn->method('rollBack');

        $uow = $this->makeUow($conn);

        $caught = null;
        try {
            $uow->run(function () { throw new \InvalidArgumentException('bad input'); });
        } catch (\InvalidArgumentException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('bad input', $caught->getMessage());
    }
}
