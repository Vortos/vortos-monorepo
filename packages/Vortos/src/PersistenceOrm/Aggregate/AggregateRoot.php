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
 * and restoreVersion() to route through the ORM-managed $ormVersion property,
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
 * introduces $ormVersion (mapped via #[ORM\Version]) and overrides all three
 * version methods to delegate to it, so Doctrine and the framework always
 * read and write the same number.
 *
 * ## Version lifecycle with #[ORM\Version]
 *
 * On INSERT: Doctrine stores whatever value $ormVersion holds (starts at 0).
 * On UPDATE: Doctrine executes WHERE version = $ormVersion SET version = version + 1,
 *            then updates $ormVersion on the entity to the incremented value.
 * On conflict: Doctrine throws OptimisticLockException — OrmWriteRepository
 *              translates this to the domain OptimisticLockException.
 */
#[ORM\MappedSuperclass]
abstract class AggregateRoot extends BaseAggregateRoot
{
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $ormVersion = 0;

    public function getVersion(): int
    {
        return $this->ormVersion;
    }

    public function incrementVersion(): void
    {
        $this->ormVersion++;
    }

    protected function restoreVersion(int $version): void
    {
        $this->ormVersion = $version;
    }
}
