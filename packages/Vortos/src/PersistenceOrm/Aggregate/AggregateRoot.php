<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Aggregate;

use Doctrine\ORM\Mapping as ORM;
use Vortos\Domain\Aggregate\AggregateRoot as BaseAggregateRoot;

/**
 * Base class for aggregates persisted via Doctrine ORM.
 *
 * Extends the domain AggregateRoot with a Doctrine-managed version column
 * for optimistic concurrency control. Override getVersion(), incrementVersion(),
 * and restoreVersion() to route through the ORM-managed $lockVersion property,
 * keeping Doctrine's change tracking and the framework's locking in sync.
 *
 * Domain aggregates extend this class directly and carry #[ORM\Entity] and
 * #[ORM\Column] annotations — one class, one source of truth:
 *
 *   #[ORM\Entity]
 *   #[ORM\Table(name: 'users')]
 *   final class User extends AggregateRoot
 *   {
 *       #[ORM\Id]
 *       #[ORM\Column(type: 'string', length: 36)]
 *       private string $id;
 *
 *       #[ORM\Column(type: 'string')]
 *       private string $email;
 *
 *       // ... domain methods
 *   }
 *
 * ## Why a separate property, not modifying the domain AggregateRoot?
 *
 * AggregateRoot::$version is private — Doctrine cannot manage it. This class
 * introduces $lockVersion (mapped via #[ORM\Version]) and overrides all three
 * version methods to delegate to it, so Doctrine and the framework always
 * read and write the same number.
 *
 * ## Version lifecycle with #[ORM\Version]
 *
 * On INSERT: Doctrine stores whatever value $lockVersion holds (starts at 0).
 * On UPDATE: Doctrine executes WHERE lock_version = $lockVersion SET lock_version = lock_version + 1,
 *            then updates $lockVersion on the entity to the incremented value.
 * On conflict: Doctrine throws OptimisticLockException — OrmStore
 *              translates this to the domain OptimisticLockException.
 *
 * ## Why incrementVersion() must NOT touch $lockVersion
 *
 * $lockVersion is an #[ORM\Version] field. Doctrine exclusively owns its
 * in-memory value after every flush: it writes `SET lock_version = lock_version + 1`
 * at the SQL layer and then syncs the PHP property back to the new value.
 *
 * If incrementVersion() also increments $lockVersion on the PHP side, the next
 * flush sees a stale WHERE clause (lock_version = N+1 instead of N) and either
 * throws a spurious OptimisticLockException or silently skips the UPDATE.
 *
 * OrmStore never calls incrementVersion() — it relies entirely on Doctrine.
 * The override exists solely to propagate the $persisted flag to the base class
 * (required by InMemoryWriteRepository in integration tests) without corrupting
 * the Doctrine-managed version counter.
 */
#[ORM\MappedSuperclass]
abstract class AggregateRoot extends BaseAggregateRoot
{
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer')]
    private int $lockVersion = 0;

    public function getVersion(): int
    {
        return $this->lockVersion;
    }

    /**
     * Propagates the $persisted flag to the base class without touching $lockVersion.
     *
     * $lockVersion is exclusively managed by Doctrine's #[ORM\Version] mechanism.
     * Any PHP-side increment before flush corrupts the optimistic-lock WHERE clause.
     */
    public function incrementVersion(): void
    {
        parent::incrementVersion();
    }

    protected function restoreVersion(int $lockVersion): void
    {
        $this->lockVersion = $lockVersion;
        parent::restoreVersion($lockVersion);
    }
}
