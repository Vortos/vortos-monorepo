<?php

declare(strict_types=1);

namespace Vortos\Tests\PersistenceOrm;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\OptimisticLockException as DoctrineOptimisticLockException;
use PHPUnit\Framework\TestCase;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;
use Vortos\Domain\Repository\Exception\OptimisticLockException;
use Vortos\PersistenceOrm\Aggregate\OrmAggregateRoot;
use Vortos\PersistenceOrm\Write\OrmWriteRepository;

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

final class RepoTestRepository extends OrmWriteRepository
{
    protected function entityClass(): string
    {
        return RepoTestAggregate::class;
    }
}

// --- tests ---

final class OrmWriteRepositoryTest extends TestCase
{
    public function test_find_by_id_delegates_to_em_find(): void
    {
        $agg = new RepoTestAggregate();
        $id  = $agg->getId();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('find')
            ->with(RepoTestAggregate::class, (string) $id)
            ->willReturn($agg);

        $repo   = new RepoTestRepository($em);
        $result = $repo->findById($id);

        $this->assertSame($agg, $result);
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $id = RepoTestId::generate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $repo = new RepoTestRepository($em);
        $this->assertNull($repo->findById($id));
    }

    public function test_save_calls_persist_and_flush(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($agg);
        $em->expects($this->once())->method('flush');

        (new RepoTestRepository($em))->save($agg);
    }

    public function test_save_wraps_doctrine_optimistic_lock_exception(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush')->willThrowException(
            DoctrineOptimisticLockException::lockFailed($agg, 1),
        );

        $this->expectException(OptimisticLockException::class);

        (new RepoTestRepository($em))->save($agg);
    }

    public function test_delete_calls_remove_and_flush(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($agg);
        $em->expects($this->once())->method('flush');

        (new RepoTestRepository($em))->delete($agg);
    }

    public function test_delete_wraps_doctrine_optimistic_lock_exception(): void
    {
        $agg = new RepoTestAggregate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('remove');
        $em->method('flush')->willThrowException(
            DoctrineOptimisticLockException::lockFailed($agg, 1),
        );

        $this->expectException(OptimisticLockException::class);

        (new RepoTestRepository($em))->delete($agg);
    }
}
