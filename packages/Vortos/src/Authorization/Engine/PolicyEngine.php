<?php

declare(strict_types=1);

namespace Vortos\Authorization\Engine;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Contract\PolicyRegistryInterface;
use Vortos\Authorization\Exception\AccessDeniedException;
use Vortos\Authorization\Exception\PolicyNotFoundException;

/**
 * Central authorization engine.
 *
 * Parses permission strings, finds the correct policy, evaluates it.
 * Used by AuthorizationMiddleware and directly by handlers that need
 * programmatic authorization checks.
 *
 * ## Permission format
 *
 *   resource.action.scope
 *
 *   'athletes.update.own'         — athlete resource, update action, own scope
 *   'competitions.create.any'     — competition resource, create action, any scope
 *   'users.delete.global'         — user resource, delete action, global scope
 *
 * ## Direct usage in handlers
 *
 *   final class UpdateAthleteHandler
 *   {
 *       public function __construct(
 *           private PolicyEngine $policy,
 *           private CurrentUserProvider $currentUser,
 *       ) {}
 *
 *       public function __invoke(UpdateAthleteCommand $command): Athlete
 *       {
 *           $athlete = $this->athleteRepository->findById($command->athleteId);
 *
 *           // Throws AccessDeniedException if not allowed
 *           $this->policy->authorize(
 *               $this->currentUser->get(),
 *               'athletes.update.own',
 *               ['athleteId' => (string) $athlete->getId(), 'federationId' => $athlete->getFederationId()],
 *           );
 *
 *           // ... proceed with update
 *       }
 *   }
 *
 * ## Soft check (returns bool instead of throwing)
 *
 *   if ($this->policy->can($identity, 'athletes.delete.any', $resource)) {
 *       // show delete button
 *   }
 */
final class PolicyEngine
{
    public function __construct(private PolicyRegistryInterface $registry) {}

    /**
     * Check if identity is authorized. Returns true/false. Never throws.
     *
     * @param UserIdentityInterface $identity   The authenticated user
     * @param string                $permission Permission string — resource.action.scope
     * @param mixed                 $resource   Optional resource for scope checks
     */
    public function can(
        UserIdentityInterface $identity,
        string $permission,
        mixed $resource = null,
    ): bool {
        if (!$identity->isAuthenticated()) {
            return false;
        }

        try {
            [$resourceType, $action, $scope] = $this->parsePermission($permission);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (!$this->registry->hasForResource($resourceType)) {
            return false;
        }

        try {
            $policy = $this->registry->findForResource($resourceType);
            return $policy->can($identity, $action, $scope, $resource);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Authorize or throw AccessDeniedException.
     *
     * Call this when access denial should abort execution.
     * Throws 403 for authenticated users who lack permission.
     * Throws 401 for unauthenticated users.
     *
     * @throws AccessDeniedException
     */
    public function authorize(
        UserIdentityInterface $identity,
        string $permission,
        mixed $resource = null,
    ): void {
        if (!$identity->isAuthenticated()) {
            throw AccessDeniedException::unauthenticated($permission);
        }

        if (!$this->can($identity, $permission, $resource)) {
            throw AccessDeniedException::forbidden($identity->id(), $permission);
        }
    }

    /**
     * Parse a permission string into [resource, action, scope].
     *
     * @throws \InvalidArgumentException If format is invalid
     * @return array{0: string, 1: string, 2: string}
     */
    public function parsePermission(string $permission): array
    {
        $parts = explode('.', $permission);

        if (count($parts) !== 3) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid permission format "%s". Expected resource.action.scope — e.g. "athletes.update.own".',
                $permission,
            ));
        }

        return $parts;
    }
}
