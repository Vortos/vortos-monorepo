<?php

declare(strict_types=1);

namespace Vortos\Auth\Attribute;

use Attribute;

/**
 * Marks a controller as requiring authentication.
 *
 * AuthMiddleware checks for this attribute on every request.
 * If the controller has #[RequiresAuth] and no valid Bearer token is present,
 * the middleware returns HTTP 401 before the controller method is called.
 *
 * ## Usage
 *
 *   #[AsController]
 *   #[Route('/api/users', methods: ['GET'])]
 *   #[RequiresAuth]
 *   final class ListUsersController
 *   {
 *       public function __invoke(Request $request): JsonResponse
 *       {
 *           // Identity is always authenticated here
 *           $identity = $this->currentUser->get();
 *           // ...
 *       }
 *   }
 *
 * ## Public routes
 *
 * Routes without #[RequiresAuth] are public — no token required.
 * The identity available in public routes is AnonymousIdentity.
 *
 * ## Role-based access
 *
 * #[RequiresAuth] only checks authentication (is there a valid token?).
 * Role-based access control is handled by the authorization module
 * using #[RequiresPermission('resource.action.scope')].
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RequiresAuth {}
