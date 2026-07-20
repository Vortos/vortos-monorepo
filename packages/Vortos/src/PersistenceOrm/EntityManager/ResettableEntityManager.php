<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\EntityManager;

use DateTimeInterface;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Mapping;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\UnitOfWork;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Wraps Doctrine EntityManager to survive FrankenPHP persistent worker mode.
 *
 * In worker mode the DI container lives across many requests. If the inner
 * EntityManager is closed by an unexpected exception path (e.g. an optimistic
 * lock exception thrown outside a DBAL transaction), reset() recreates it from
 * the same DBAL Connection and Configuration — no reflection hacks, no closed
 * state leaking across request boundaries.
 *
 * All callers inject EntityManagerInterface and therefore receive this wrapper.
 * When reset() replaces $inner, every caller automatically sees the fresh
 * EntityManager on the next request without needing their own ResetInterface.
 *
 * The DBAL Connection is reused across recreations — it is the same PHP object
 * and carries no closed-EM state, so OutboxWriter and other services that hold
 * the Connection directly are unaffected.
 */
final class ResettableEntityManager implements EntityManagerInterface, ResetInterface
{
    private EntityManager $inner;

    public function __construct(EntityManager $inner)
    {
        $this->inner = $inner;
    }

    public function reset(): void
    {
        if (!$this->inner->isOpen()) {
            $enabledFilters = array_keys($this->inner->getFilters()->getEnabledFilters());

            $conn = $this->inner->getConnection();
            $conn->close();

            $this->inner = new EntityManager(
                $conn,
                $this->inner->getConfiguration(),
                $this->inner->getEventManager(),
            );

            foreach ($enabledFilters as $name) {
                $this->inner->getFilters()->enable($name);
            }
        }

        $this->inner->clear();
    }

    // -------------------------------------------------------------------------
    // EntityManagerInterface
    // -------------------------------------------------------------------------

    public function getConnection(): Connection
    {
        return $this->inner->getConnection();
    }

    public function getExpressionBuilder(): Expr
    {
        return $this->inner->getExpressionBuilder();
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function wrapInTransaction(callable $func): mixed
    {
        return $this->inner->wrapInTransaction($func);
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }

    public function createQuery(string $dql = ''): Query
    {
        return $this->inner->createQuery($dql);
    }

    public function createNativeQuery(string $sql, ResultSetMapping $rsm): NativeQuery
    {
        return $this->inner->createNativeQuery($sql, $rsm);
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return $this->inner->createQueryBuilder();
    }

    public function find(string $className, mixed $id, LockMode|int|null $lockMode = null, int|null $lockVersion = null): object|null
    {
        return $this->inner->find($className, $id, $lockMode, $lockVersion);
    }

    public function getReference(string $entityName, mixed $id): object|null
    {
        return $this->inner->getReference($entityName, $id);
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function lock(object $entity, LockMode|int $lockMode, DateTimeInterface|int|null $lockVersion = null): void
    {
        $this->inner->lock($entity, $lockMode, $lockVersion);
    }

    public function getEventManager(): EventManager
    {
        return $this->inner->getEventManager();
    }

    public function getConfiguration(): Configuration
    {
        return $this->inner->getConfiguration();
    }

    public function isOpen(): bool
    {
        return $this->inner->isOpen();
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->inner->getUnitOfWork();
    }

    public function newHydrator(string|int $hydrationMode): AbstractHydrator
    {
        return $this->inner->newHydrator($hydrationMode);
    }

    public function getProxyFactory(): ProxyFactory
    {
        return $this->inner->getProxyFactory();
    }

    public function getFilters(): FilterCollection
    {
        return $this->inner->getFilters();
    }

    public function isFiltersStateClean(): bool
    {
        return $this->inner->isFiltersStateClean();
    }

    public function hasFilters(): bool
    {
        return $this->inner->hasFilters();
    }

    public function getCache(): Cache|null
    {
        return $this->inner->getCache();
    }

    // -------------------------------------------------------------------------
    // ObjectManager (via EntityManagerInterface)
    // -------------------------------------------------------------------------

    public function persist(object $object): void
    {
        $this->inner->persist($object);
    }

    public function remove(object $object): void
    {
        $this->inner->remove($object);
    }

    public function clear(): void
    {
        $this->inner->clear();
    }

    public function detach(object $object): void
    {
        $this->inner->detach($object);
    }

    public function refresh(object $object, LockMode|int|null $lockMode = null): void
    {
        $this->inner->refresh($object, $lockMode);
    }

    public function flush(): void
    {
        $this->inner->flush();
    }

    /** @param class-string<T> $className @template T of object */
    public function getRepository(string $className): EntityRepository
    {
        return $this->inner->getRepository($className);
    }

    public function getClassMetadata(string $className): Mapping\ClassMetadata
    {
        return $this->inner->getClassMetadata($className);
    }

    public function getMetadataFactory(): ClassMetadataFactory
    {
        return $this->inner->getMetadataFactory();
    }

    public function initializeObject(object $obj): void
    {
        $this->inner->initializeObject($obj);
    }

    public function isUninitializedObject(mixed $value): bool
    {
        return $this->inner->isUninitializedObject($value);
    }

    public function contains(object $object): bool
    {
        return $this->inner->contains($object);
    }
}
