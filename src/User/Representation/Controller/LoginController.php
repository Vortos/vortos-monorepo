<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Contract\PasswordHasherInterface;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[Route('/api/auth/login', methods: ['POST'])]
final class LoginController
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly PasswordHasherInterface $hasher,
        private readonly UserRepository $userRepository,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data     = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if ($email === '' || $password === '') {
            return new JsonResponse([
                'error'      => 'validation_failed',
                'message'    => 'The given data was invalid.',
                'violations' => array_filter([
                    'email'    => $email === '' ? ['This value is required.'] : null,
                    'password' => $password === '' ? ['This value is required.'] : null,
                ]),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->userRepository->findByEmail($email);

        if ($user === null || !$this->hasher->verify($password, $user->getPasswordHash())) {
            return new JsonResponse(
                ['error' => 'Invalid credentials.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return new JsonResponse(
            $this->jwtService->issue(new UserIdentity(
                id:    (string) $user->getId(),
                roles: $user->getRoles(),
            ))->toArray(),
        );
    }
}
