<?php

namespace App\User\Domain\Event;

use DateTimeImmutable;
use Symfony\Component\Uid\UuidV7;
use Vortos\Domain\Event\DomainEventInterface;

final readonly class UserCreatedEvent implements DomainEventInterface
{
    public function __construct(
        public string $id ,
        public string $name ,
        public string $email 
    ){
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function eventVersion(): int
    {
        return 1;
    }
}