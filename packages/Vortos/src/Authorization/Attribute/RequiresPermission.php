<?php

declare(strict_types=1);

namespace Vortos\Authorization\Attribute;

use Attribute;

/**
 * Declares the permission required to access a controller or handler.
 *
 * Permission format: resource.action.scope
 *
 *   resource — what is being acted on    (athletes, competitions, users)
 *   action   — what is being done        (create, read, update, delete, list)
 *   scope    — who is allowed            (any, own, federation)
 *
 * ## Controller usage
 *
 *   #[RequiresPermission('athletes.update.own')]
 *   final class UpdateAthleteController { ... }
 *
 * ## Handler usage
 *
 *   #[RequiresPermission('competitions.create.federation')]
 *   #[AsCommandHandler]
 *   final class CreateCompetitionHandler { ... }
 *
 * ## Multiple permissions (all must pass)
 *
 *   #[RequiresPermission('athletes.read.any')]
 *   #[RequiresPermission('reports.export.federation')]
 *   final class ExportAthleteReportController { ... }
 *
 * ## Scope semantics
 *
 *   any        — any authenticated user with this role can perform this action
 *   own        — only the resource owner can perform this action
 *   federation — only users in the same federation can perform this action
 *   global     — super-admin only
 *
 * ## Implies #[RequiresAuth]
 *
 * A controller with #[RequiresPermission] implicitly requires authentication.
 * AuthorizationMiddleware returns 401 for unauthenticated requests before
 * evaluating permissions, so #[RequiresAuth] is not needed alongside this.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequiresPermission
{
    public function __construct(
        /**
         * The permission string in resource.action.scope format.
         * e.g. 'athletes.update.own', 'competitions.create.federation'
         */
        public readonly string $permission,

        /**
         * Optional resource parameter name to extract from route attributes.
         * Used for ownership and federation scope checks.
         *
         * e.g. resourceParam: 'athleteId' — the middleware fetches the resource
         * using this route parameter and passes it to the policy for scope evaluation.
         */
        public readonly ?string $resourceParam = null,
    ) {}
}
