<?php

declare(strict_types=1);

namespace App\User\Domain;

use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;

final class User extends AggregateRoot
{
    private function __construct(
        private UserId $id,
    ) {}

    public static function create(UserId $id): self
    {
        $instance = new self($id);

        return $instance;
    }

    public static function reconstruct(UserId $id, int $version): self
    {
        $instance = new self($id);
        $instance->restoreVersion($version);

        return $instance;
    }

    public function getId(): AggregateId
    {
        return $this->id;
    }
}
