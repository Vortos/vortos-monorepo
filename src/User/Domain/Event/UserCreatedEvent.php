<?php

namespace App\User\Domain\Event;

use App\User\Infrastructure\Topics\UserTopics;
use Fortizan\Tekton\Bus\Event\Attribute\AsEvent;
use Fortizan\Tekton\Domain\Event\DomainEventInterface;
use Symfony\Component\Uid\UuidV7;

#[AsEvent(channel: 'async', topic: UserTopics::UserCreated, version: 'v5')]
#[AsEvent(channel: 'rt', topic: UserTopics::UserCreated, version: 'v2')]
final readonly class UserCreatedEvent implements DomainEventInterface
{
    public function __construct(
        public UuidV7 $id ,
        public string $name ,
        public string $email 
    ){
    }
}