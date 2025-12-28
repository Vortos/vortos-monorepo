<?php

namespace App\User\Representation\Controller;

use Fortizan\Tekton\Attribute\ApiController;
use Fortizan\Tekton\Persistence\PersistenceManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[ApiController]
#[Route(name:'user.mongo', path:'/user/read')]
class TestMongoController
{
    public function __construct(
        private PersistenceManager $persistenceManager
    ){
    }

    public function __invoke():Response
    {  
        $user = $this->persistenceManager->projectionReader()->get('user', '019b63cd-fc8b-7c75-9bec-1cf85370739d');

        return new JsonResponse($user);
    }
}