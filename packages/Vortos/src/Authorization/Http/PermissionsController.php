<?php

declare(strict_types=1);

namespace Vortos\Authorization\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Voter\RoleVoter;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[Route('/api/me/permissions', name: 'vortos.me.permissions', methods: ['GET'])]
final class PermissionsController
{
    public function __construct(
        private readonly CurrentUserProvider $currentUser,
        private readonly RoleVoter $roleVoter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) {
            return new JsonResponse(['permissions' => []]);
        }

        return new JsonResponse([
            'permissions' => $this->roleVoter->expand($identity),
        ]);
    }
}
