<?php

declare(strict_types=1);

namespace App\User\Representation\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[Route('/api/auth/logout', methods: ['POST'])]
#[RequiresAuth]
final class LogoutController
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly CurrentUserProvider $currentUserProvider,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $identity = $this->currentUserProvider->get();
        $this->jwtService->revokeAll($identity->id());

        return new JsonResponse(['message' => 'Logged out successfully.']);
    }
}
