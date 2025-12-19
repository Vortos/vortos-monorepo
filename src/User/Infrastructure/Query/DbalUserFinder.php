<?php

namespace App\User\Infrastructure\Query;

use Doctrine\DBAL\Connection;

class DbalUserFinder
{
    public function __construct(
        private Connection $connection
    ){
    }

    public function findByEmail(string $email):array
    {
        $query = "SELECT * FROM users WHERE email = ?";
        return $this->connection->fetchAssociative($query, [$email]);
    }
}