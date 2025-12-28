<?php

namespace App\User\Domain\Event;

use Symfony\Component\Uid\UuidV7;

final readonly class UserCreatedEvent 
{
    public function __construct(
        public UuidV7 $id ,
        public string $name ,
        public string $email 
    ){
    }
}