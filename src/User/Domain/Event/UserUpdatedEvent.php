<?php

namespace App\User\Domain\Event;

use DateTimeImmutable;
use Vortos\Domain\Event\DomainEventInterface;
use Symfony\Component\Uid\UuidV7;

final readonly class UserUpdatedEvent implements DomainEventInterface
{
    public function __construct(
        public UuidV7 $id ,
        public string $name ,
        public string $email 
    ){
    }

    public function aggregateId(): string
    {
        return $this->id->toRfc4122();
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