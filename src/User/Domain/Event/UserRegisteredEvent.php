<?php

declare(strict_types=1);

namespace App\User\Domain\Event;

use Vortos\Domain\Event\DomainEvent;

final readonly class UserRegisteredEvent extends DomainEvent
{
    public function __construct(
        string $aggregateId,
        public readonly string $email,
        public readonly string $name,
    ) {
        parent::__construct($aggregateId);
    }
}
