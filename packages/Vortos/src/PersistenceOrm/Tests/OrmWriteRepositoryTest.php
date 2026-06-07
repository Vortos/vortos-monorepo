<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests;

use Doctrine\DBAL\Exception\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\Exception\DeadlockException as DbalDeadlockException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException as DbalForeignKeyException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException as DbalLockWaitTimeoutException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException as DbalNotNullException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException as DbalUniqueException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\OptimisticLockException as DoctrineOptimisticLockException;
use PHPUnit\Framework\TestCase;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;
use Vortos\Domain\Repository\Exception\OptimisticLockException;
use Vortos\PersistenceOrm\Aggregate\AggregateRoot as OrmAggregateRoot;
use Vortos\PersistenceOrm\Exception\ConnectionException;
use Vortos\PersistenceOrm\Exception\DeadlockException;
use Vortos\PersistenceOrm\Exception\ForeignKeyConstraintException;
use Vortos\PersistenceOrm\Exception\LockWaitTimeoutException;
use Vortos\PersistenceOrm\Exception\NotNullConstraintException;
use Vortos\PersistenceOrm\Exception\UniqueConstraintException;
use Vortos\PersistenceOrm\Write\OrmStore;

// --- fixtures ---

final class RepoTestId extends AggregateId {}

#[ORM\Entity]
final class RepoTestAggregate extends OrmAggregateRoot
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private string $id;

    public function __construct()
    {
        $this->id = (string) RepoTestId::generate();
    }

    public function getId(): RepoTestId
    {
        return RepoTestId::fromString($this->id);
    }
}

// --- tests ---

final class OrmWriteRepositoryTest extends TestCase
{
    private function store(EntityManagerInterface $em): OrmStore
    {
        return new OrmStore($em, RepoTestAggregate::class);
    }

    public function test_find_delegates_to_em_find(): void
    {
        $agg = new RepoTestAggregate();
        $id  = $agg->getId();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('find')
            ->with(RepoTestAggregate::class, (string) $id)
            ->willReturn($agg);

        $result = $this->store($em)->find($id);

        $this->assertSame($agg, $result);
    }

    public function test_find_returns_null_when_not_found(): void
    {
        $id = RepoTestId::generate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $this->assertNull($this->store($em)->find($id));
    }

    public function test_save_calls_persist_and_flush_for_new_aggregate(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->expects($this->once())->method('persist')->with($agg);
        $em->expects($this->once())->method('flush');

        $this->store($em)->save($agg);
    }

    public function test_save_skips_persist_for_managed_aggregate(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $this->store($em)->save($agg);
    }

    public function test_save_wraps_doctrine_optimistic_lock_exception(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('persist');
        $em->method('flush')->willThrowException(
            DoctrineOptimisticLockException::lockFailed($agg),
        );

        $this->expectException(OptimisticLockException::class);

        $this->store($em)->save($agg);
    }

    public function test_delete_calls_remove_and_flush_for_managed_aggregate(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);
        $em->expects($this->once())->method('remove')->with($agg);
        $em->expects($this->once())->method('flush');

        $this->store($em)->delete($agg);
    }

    public function test_delete_uses_find_for_unmanaged_aggregate(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('find')->with(RepoTestAggregate::class, (string) $agg->getId())->willReturn($agg);
        $em->expects($this->once())->method('remove')->with($agg);
        $em->expects($this->once())->method('flush');

        $this->store($em)->delete($agg);
    }

    public function test_delete_is_no_op_when_aggregate_not_found(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('find')->willReturn(null);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $this->store($em)->delete($agg);
    }

    public function test_delete_wraps_doctrine_optimistic_lock_exception(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);
        $em->method('remove');
        $em->method('flush')->willThrowException(
            DoctrineOptimisticLockException::lockFailed($agg),
        );

        $this->expectException(OptimisticLockException::class);

        $this->store($em)->delete($agg);
    }

    // --- DBAL exception translation ---

    public function test_save_translates_unique_constraint_violation(): void
    {
        $this->assertSaveTranslates(DbalUniqueException::class, UniqueConstraintException::class);
    }

    public function test_save_translates_foreign_key_violation(): void
    {
        $this->assertSaveTranslates(DbalForeignKeyException::class, ForeignKeyConstraintException::class);
    }

    public function test_save_translates_not_null_violation(): void
    {
        $this->assertSaveTranslates(DbalNotNullException::class, NotNullConstraintException::class);
    }

    public function test_save_translates_deadlock(): void
    {
        $this->assertSaveTranslates(DbalDeadlockException::class, DeadlockException::class);
    }

    public function test_save_translates_lock_wait_timeout(): void
    {
        $this->assertSaveTranslates(DbalLockWaitTimeoutException::class, LockWaitTimeoutException::class);
    }

    public function test_save_translates_connection_exception(): void
    {
        $this->assertSaveTranslates(DbalConnectionException::class, ConnectionException::class);
    }

    public function test_delete_translates_unique_constraint_violation(): void
    {
        $this->assertDeleteTranslates(DbalUniqueException::class, UniqueConstraintException::class);
    }

    public function test_delete_translates_deadlock(): void
    {
        $this->assertDeleteTranslates(DbalDeadlockException::class, DeadlockException::class);
    }

    public function test_save_wraps_unknown_dbal_exception_as_persistence_exception(): void
    {
        $agg           = new RepoTestAggregate();
        $dbalException = $this->createMock(\Doctrine\DBAL\Exception::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('persist');
        $em->method('flush')->willThrowException($dbalException);

        $this->expectException(\Vortos\PersistenceOrm\Exception\PersistenceException::class);

        $this->store($em)->save($agg);
    }

    public function test_save_does_not_wrap_non_dbal_exception(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('persist');
        $em->method('flush')->willThrowException(new \DomainException('domain error'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('domain error');

        $this->store($em)->save($agg);
    }

    public function test_translated_exception_wraps_original_as_cause(): void
    {
        $agg      = new RepoTestAggregate();
        $original = $this->createMock(DbalUniqueException::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('persist');
        $em->method('flush')->willThrowException($original);

        try {
            $this->store($em)->save($agg);
            $this->fail('Expected UniqueConstraintException');
        } catch (UniqueConstraintException $e) {
            $this->assertSame($original, $e->getPrevious());
        }
    }

    private function assertSaveTranslates(string $dbalClass, string $vortosClass): void
    {
        $agg       = new RepoTestAggregate();
        $exception = $this->createMock($dbalClass);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('persist');
        $em->method('flush')->willThrowException($exception);

        $this->expectException($vortosClass);

        $this->store($em)->save($agg);
    }

    private function assertDeleteTranslates(string $dbalClass, string $vortosClass): void
    {
        $agg       = new RepoTestAggregate();
        $exception = $this->createMock($dbalClass);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);
        $em->method('remove');
        $em->method('flush')->willThrowException($exception);

        $this->expectException($vortosClass);

        $this->store($em)->delete($agg);
    }
}
