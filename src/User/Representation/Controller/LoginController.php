<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use App\User\Infrastructure\Database\UserWriteRepository;
use App\User\Domain\Email;
use Vortos\Http\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\Exception\UnauthorizedException;
use Vortos\Http\Request;
use Vortos\Security\Csrf\Attribute\SkipCsrf;

#[AsController]
#[Route('/api/users/login', name: 'users.login', methods: ['POST'])]
#[SkipCsrf]
final class LoginController
{
    public function __construct(
        private readonly UserWriteRepository $repository,
        private readonly JwtService $jwtService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $email    = $body['email'] ?? '';
        $password = $body['password'] ?? '';

        try {
            $user = $this->repository->findByEmail(new Email($email));
        } catch (\InvalidArgumentException) {
            throw new UnauthorizedException('Invalid credentials.');
        }

        if ($user === null || !password_verify($password, $user->getPasswordHash())) {
            throw new UnauthorizedException('Invalid credentials.');
        }

        $identity = new UserIdentity(
            id:    (string) $user->getId(),
            roles: ['ROLE_USER'],
        );

        $token = $this->jwtService->issue($identity);

        return new JsonResponse($token->toArray());
    }
}
