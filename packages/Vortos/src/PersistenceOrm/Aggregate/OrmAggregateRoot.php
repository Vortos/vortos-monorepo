<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Aggregate;

use Doctrine\ORM\Mapping as ORM;
use Vortos\Domain\Aggregate\AggregateRoot;

/**
 * MappedSuperclass for aggregates persisted via Doctrine ORM.
 *
 * Extends AggregateRoot with an ORM-managed version column.
 * Doctrine owns the version field — it increments it automatically
 * on flush and throws OptimisticLockException on concurrent modification.
 *
 * ## Why a separate class, not modifying AggregateRoot?
 *
 * AggregateRoot is in the domain layer — it must stay free of ORM annotations.
 * This class lives in infrastructure and adds only the version column that
 * Doctrine needs to enforce optimistic locking.
 *
 * ## Usage
 *
 *   #[ORM\Entity]
 *   #[ORM\Table(name: 'users')]
 *   final class User extends OrmAggregateRoot
 *   {
 *       #[ORM\Id]
 *       #[ORM\Column(type: 'string', length: 36)]
 *       private string $id;
 *
 *       // ... other columns
 *   }
 */
#[ORM\MappedSuperclass]
abstract class OrmAggregateRoot extends AggregateRoot
{
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    protected int $ormVersion = 0;

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
