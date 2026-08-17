<?php

declare(strict_types=1);

namespace Vortos\Authorization\Http;

use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Auth\Attribute\RequiresAuth;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Http\Attribute\AsController;

#[AsController]
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
            // Never cached, and the answer is per-identity rather than per-URL.
            //
            // `private, max-age=30` was wrong in a way that only shows up with more
            // than one tenant: the browser keys its cache on the URL, the bearer
            // token is not part of that key, and nothing here sent `Vary`. Switching
            // organisations re-mints a tenant-scoped token and asks this same URL, so
            // for the next 30 seconds the response served was the permission list of
            // the organisation the caller had just left — an admin returning to their
            // own organisation arrived with someone else's authority, denied by every
            // gate until the entry aged out.
            //
            // `Vary: Authorization` would fix the collision, but a permission list is
            // exactly the kind of answer that must not be replayed from a disk cache
            // after a role change, so this does not store it at all. The resolver
            // behind it already caches in Redis; the network round trip is the cheap
            // part. Vary stays as defence for any intermediary that ignores no-store.
            'Cache-Control' => 'no-store, private',
            'Vary'          => 'Authorization',
        ]);
    }
}
