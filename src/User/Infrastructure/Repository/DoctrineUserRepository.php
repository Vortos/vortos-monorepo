<?php

namespace App\User\Infrastructure\Repository;

use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManager;

class DoctrineUserRepository
{
    public function __construct(
        private EntityManager $entityManager
    ){
    }

    public function save(User $user):void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}