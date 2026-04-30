<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Exception\TokenExpiredException;
use Vortos\Auth\Exception\TokenInvalidException;
use Vortos\Auth\Exception\TokenRevokedException;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[Route('/api/auth/refresh', methods: ['POST'])]
final class RefreshTokenController
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly UserRepository $userRepository,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data         = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $refreshToken = trim($data['refresh_token'] ?? '');

        if ($refreshToken === '') {
            return new JsonResponse([
                'error'      => 'validation_failed',
                'message'    => 'The given data was invalid.',
                'violations' => ['refresh_token' => ['This value is required.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $userId = $this->jwtService->getUserIdFromRefreshToken($refreshToken);
            $user   = $this->userRepository->findById(\App\User\Domain\Entity\UserId::fromString($userId));

            if ($user === null) {
                return new JsonResponse(['error' => 'User not found.'], Response::HTTP_UNAUTHORIZED);
            }

            $identity  = new UserIdentity(id: (string) $user->getId(), roles: $user->getRoles());
            $tokenPair = $this->jwtService->refresh($refreshToken, $identity);
        } catch (TokenExpiredException) {
            return new JsonResponse(['error' => 'Refresh token expired.'], Response::HTTP_UNAUTHORIZED);
        } catch (TokenRevokedException) {
            return new JsonResponse(['error' => 'Refresh token revoked.'], Response::HTTP_UNAUTHORIZED);
        } catch (TokenInvalidException) {
            return new JsonResponse(['error' => 'Invalid refresh token.'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($tokenPair->toArray());
    }
}
