<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use PHPUnit\Framework\TestCase;
use Vortos\PersistenceOrm\EntityManager\ResettableEntityManager;

/**
 * Unit tests for ResettableEntityManager.
 *
 * The closed-EM reset path rebuilds the inner EM. It was previously assumed to need a real
 * connection and was left to integration tests — that gap let a Doctrine ORM 3 incompatibility
 * (`EntityManager::create()` was removed in 3.0) ship and take production down, so the rebuild
 * is now asserted here directly.
 */
final class ResettableEntityManagerTest extends TestCase
{
    private function makeInner(bool $isOpen = true): EntityManager
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->method('getEnabledFilters')->willReturn([]);

        $conn = $this->createMock(Connection::class);

        $em = $this->createMock(EntityManager::class);
        $em->method('isOpen')->willReturn($isOpen);
        $em->method('getFilters')->willReturn($filters);
        $em->method('getConnection')->willReturn($conn);

        return $em;
    }

    public function test_delegates_flush_to_inner(): void
    {
        $em = $this->makeInner();
        $em->expects($this->once())->method('flush');

        (new ResettableEntityManager($em))->flush();
    }

    public function test_delegates_persist_to_inner(): void
    {
        $em = $this->makeInner();
        $object = new \stdClass();
        $em->expects($this->once())->method('persist')->with($object);

        (new ResettableEntityManager($em))->persist($object);
    }

    public function test_delegates_clear_to_inner(): void
    {
        $em = $this->makeInner();
        $em->expects($this->once())->method('clear');

        (new ResettableEntityManager($em))->clear();
    }

    public function test_delegates_remove_to_inner(): void
    {
        $em = $this->makeInner();
        $object = new \stdClass();
        $em->expects($this->once())->method('remove')->with($object);

        (new ResettableEntityManager($em))->remove($object);
    }

    public function test_delegates_contains_to_inner(): void
    {
        $object = new \stdClass();
        $em = $this->makeInner();
        $em->method('contains')->with($object)->willReturn(true);

        $this->assertTrue((new ResettableEntityManager($em))->contains($object));
    }

    public function test_delegates_is_open_to_inner(): void
    {
        $this->assertTrue((new ResettableEntityManager($this->makeInner(true)))->isOpen());
        $this->assertFalse((new ResettableEntityManager($this->makeInner(false)))->isOpen());
    }

    public function test_delegates_get_connection_to_inner(): void
    {
        $conn = $this->createMock(Connection::class);

        $filters = $this->createMock(FilterCollection::class);
        $filters->method('getEnabledFilters')->willReturn([]);

        $em = $this->createMock(EntityManager::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('getFilters')->willReturn($filters);
        $em->method('getConnection')->willReturn($conn);

        $this->assertSame($conn, (new ResettableEntityManager($em))->getConnection());
    }

    public function test_reset_clears_identity_map_when_em_is_open(): void
    {
        $em = $this->makeInner(true);
        $em->expects($this->once())->method('clear');
        $em->expects($this->never())->method('getConfiguration');

        (new ResettableEntityManager($em))->reset();
    }

    public function test_reset_does_not_close_connection_when_em_is_open(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->never())->method('close');

        $em = $this->makeInner(true);
        $em->method('getConnection')->willReturn($conn);

        (new ResettableEntityManager($em))->reset();
    }

    /**
     * Regression: a closed inner EM must actually be rebuilt.
     *
     * The rebuild previously used the ORM 2 static factory `EntityManager::create()`, removed in
     * ORM 3. Because reset() is the only path that recovers a closed EM and it fataled before
     * reassigning the inner instance, the EM stayed closed for the life of a FrankenPHP worker
     * thread and every later request on it failed with "The EntityManager is closed."
     */
    public function test_reset_rebuilds_a_closed_entity_manager(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl($this->createMock(MappingDriver::class));
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('VortosTestProxies');

        $filters = $this->createMock(FilterCollection::class);
        $filters->method('getEnabledFilters')->willReturn([]);

        $inner = $this->createMock(EntityManager::class);
        $inner->method('isOpen')->willReturn(false);
        $inner->method('getFilters')->willReturn($filters);
        $inner->method('getConnection')->willReturn($this->createMock(Connection::class));
        $inner->method('getConfiguration')->willReturn($config);
        $inner->method('getEventManager')->willReturn(new EventManager());

        $wrapper = new ResettableEntityManager($inner);
        $wrapper->reset();

        // Rebuilt from the same Configuration, so the wrapper is usable again.
        $this->assertTrue($wrapper->isOpen());
    }
}
