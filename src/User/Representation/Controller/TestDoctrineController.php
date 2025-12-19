<?php

namespace App\User\Representation\Controller;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Query\DbalUserFinder;
use App\User\Infrastructure\Repository\DoctrineUserRepository;
use Fortizan\Tekton\Attribute\ApiController;
use Fortizan\Tekton\Database\DoctrineFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(name:'user.db', path:'user/db')]
#[ApiController]
class TestDoctrineController 
{
    public function __invoke() : Response
    {
        $entityFactory = new DoctrineFactory();

        $connectionParams = [
            'host' => $_ENV['POSTGRES_HOST'],
            'user' => $_ENV['POSTGRES_USER'],
            'password' => $_ENV['POSTGRES_PASSWORD'],
            'dbname' => $_ENV['POSTGRES_DB'],
            'driver' => 'pdo_pgsql'
        ];
        $entityPaths = [__DIR__ . "/../../Domain/Entity"];

        $entityManager = $entityFactory->createEntityManager($connectionParams, $entityPaths, true);

        $connection = $entityFactory->createConnection($connectionParams, $entityPaths, true);

        $user = new User();
        $user->setId(1);
        $user->setName("sachintha");
        $user->setEmail('abc@gmail.com');

        $userRepo = new DoctrineUserRepository($entityManager);
        $userRepo->save($user);

        $userFinder = new DbalUserFinder($connection);
        $userResult = $userFinder->findByEmail("abc@gmail.com");


        dd($userResult);
        return new Response();
    }
}