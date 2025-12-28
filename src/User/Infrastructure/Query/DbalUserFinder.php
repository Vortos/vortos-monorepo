<?php

namespace App\User\Infrastructure\Query;

use Fortizan\Tekton\Persistence\PersistenceManager;

class DbalUserFinder
{
    public function __construct(
        private PersistenceManager $persistenceManager
    ){
    }

    public function findByEmail(string $email):array
    {
        $query = "SELECT * FROM users WHERE email = ?";
        return $this->persistenceManager->sourceReader()->fetchAssociative($query, [$email]);
    }
}