<?php

declare(strict_types=1);

namespace Vortos\Tests\Persistence\Write;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;
use Vortos\Domain\Repository\Exception\OptimisticLockException;
use Vortos\Persistence\Write\InMemoryWriteRepository;

// Minimal ID for test
final class TestId extends AggregateId {}

// Minimal aggregate for test
final class TestAggregate extends AggregateRoot
{
    public string $name;

    public function __construct(private TestId $id, string $name)
    {
        $this->name = $name;
    }

    public static function create(string $name): self
    {
        return new self(TestId::generate(), $name);
    }

    public function getId(): TestId
    {
        return $this->id;
    }
}

final class InMemoryWriteRepositoryTest extends TestCase
{
    public function test_it_throws_optimistic_lock_exception_on_stale_update(): void
    {
        $repository = new class extends InMemoryWriteRepository {};

        $aggregateA = TestAggregate::create('Alice');

        $repository->save($aggregateA); // store: v1, aggregateA: v1

        $staleAggregate = $repository->findById($aggregateA->getId()); // clone at v1

        $aggregateA->name = 'Alice Updated';
        $repository->save($aggregateA); // store: v2, aggregateA: v2

        $this->expectException(OptimisticLockException::class);

        $staleAggregate->name = 'Stale Update';
        $repository->save($staleAggregate); // conflict: store is v2, stale expects v1
    }
}
