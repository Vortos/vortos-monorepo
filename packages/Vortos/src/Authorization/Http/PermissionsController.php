<?php

declare(strict_types=1);

namespace Vortos\Authorization\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Http\Attribute\ApiController;

#[ApiController]
#[RequiresAuth]
#[Route('/api/me/permissions', name: 'vortos.me.permissions', methods: ['GET'])]
final class PermissionsController
{
    public function __construct(
        private readonly CurrentUserProvider $currentUser,
        private readonly PermissionResolverInterface $resolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $resolved = $this->resolver->resolve($identity);
        $permissions = $resolved->permissions();
        $roles = $resolved->roles();
        $expandedRoles = $resolved->expandedRoles();

        return new JsonResponse([
            'permissions' => $permissions,
            'roles' => $roles,
            'expandedRoles' => $expandedRoles,
            'version' => hash('sha256', json_encode([$permissions, $roles, $expandedRoles], JSON_THROW_ON_ERROR)),
        ], headers: [
            'Cache-Control' => 'private, max-age=30',
        ]);
    }
}
