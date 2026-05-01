<?php

declare(strict_types=1);

namespace Vortos\Tests\PersistenceOrm;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Vortos\PersistenceOrm\Transaction\OrmUnitOfWork;

final class OrmUnitOfWorkTest extends TestCase
{
    public function test_run_delegates_to_wrap_in_transaction(): void
    {
        $called = false;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(function (callable $work) use (&$called) {
                $called = true;
                return $work();
            });

        $uow = new OrmUnitOfWork($em);
        $uow->run(function () {});

        $this->assertTrue($called);
    }

    public function test_run_returns_value_from_work(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')
            ->willReturnCallback(fn(callable $work) => $work());

        $uow    = new OrmUnitOfWork($em);
        $result = $uow->run(fn() => 42);

        $this->assertSame(42, $result);
    }

    public function test_is_active_returns_true_when_transaction_open(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $uow = new OrmUnitOfWork($em);
        $this->assertTrue($uow->isActive());
    }

    public function test_is_active_returns_false_when_no_transaction(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $uow = new OrmUnitOfWork($em);
        $this->assertFalse($uow->isActive());
    }

    public function test_run_propagates_exception_from_work(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')
            ->willReturnCallback(function (callable $work) {
                return $work();
            });

        $uow = new OrmUnitOfWork($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $uow->run(function () { throw new \RuntimeException('boom'); });
    }
}
