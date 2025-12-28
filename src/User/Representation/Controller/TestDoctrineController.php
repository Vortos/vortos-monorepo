<?php

namespace App\User\Representation\Controller;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Query\DbalUserFinder;
use App\User\Infrastructure\Repository\DoctrineUserRepository;
use Fortizan\Tekton\Attribute\ApiController;
use Fortizan\Tekton\Persistence\PersistenceManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(name: 'user.db', path: 'user/write')]
#[ApiController]
class TestDoctrineController
{
    public function __construct(
        private DoctrineUserRepository $userRepo,
        private DbalUserFinder $userFinder
    ) {}

    public function __invoke(): Response
    {
        $user = User::registerUser(
            "sachintha",
            "abc@gmail.com",
            true
        );

        $this->userRepo->save($user);

        $userResult = $this->userFinder->findByEmail("abc@gmail.com");

        return new JsonResponse($userResult);
    }
}
