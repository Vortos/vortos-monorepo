<?php

namespace Vortos\Tests\Persistence\Write;

use PHPUnit\Framework\TestCase;
use App\User\Domain\Entity\User;
use Vortos\Persistence\Write\InMemoryWriteRepository;
use Vortos\Domain\Repository\Exception\OptimisticLockException;

final class InMemoryWriteRepositoryTest extends TestCase
{
    public function test_it_throws_optimistic_lock_exception_on_stale_update(): void
    {
        // 1. Create a concrete anonymous class on the fly for testing
        $repository = new class extends InMemoryWriteRepository {
            // Note: If your InMemoryWriteRepository forces you to implement 
            // any abstract methods (e.g., getting the aggregate class name), 
            // you will need to quickly implement them right here.
        };

        // 1. ARRANGE: Process A creates and saves the initial aggregate (Version 1)
        $userA = User::registerUser('John Doe', 'john@example.com', true);
        $repository->save($userA);

        // 2. Simulate Process B fetching the exact same aggregate from the database.
        // Since it's in-memory, `clone` perfectly simulates two different processes 
        // holding the same data at Version 1.
        $userB = clone $userA;

        // Process B makes a change and saves it successfully (Repository moves to Version 2)
        $userB->setName('Jane Doe');
        $repository->save($userB);

        // 3. ACT & ASSERT: Process A (which is still at Version 1) now tries to 
        // save its outdated state.
        $this->expectException(OptimisticLockException::class);

        // Process A makes a change, completely unaware that Process B already updated the DB
        $userA->setName('Johnny');

        // This save MUST fail and throw the OptimisticLockException to prevent a lost update
        $repository->save($userA);
    }

    
}