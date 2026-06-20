<?php

declare(strict_types=1);

namespace Vortos\Authorization\Engine;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Contract\EmergencyDenyListInterface;
use Vortos\Authorization\Contract\PermissionRegistryInterface;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Contract\PolicyRegistryInterface;
use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Decision\AuthorizationDecision;
use Vortos\Authorization\Decision\AuthorizationDecisionReason;
use Vortos\Authorization\Decision\PolicyDecision;
use Vortos\Authorization\Ownership\OwnerResolverRegistry;
use Vortos\Authorization\Exception\AccessDeniedException;
use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;
use Vortos\Authorization\Scope\Contract\ScopeMode;
use Vortos\Authorization\Scope\ScopeEnforcement;
use Vortos\Authorization\Scope\ScopeEnforcementClassifier;
use Vortos\Authorization\Identity\RequestAuthzVersionProvider;
use Vortos\Authorization\Tracing\AuthorizationTracer;
use Vortos\Authorization\Voter\RoleVoter;
use Vortos\Observability\Telemetry\TelemetryLabels;

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
 *
 * ## Decision model
 *
 * A request is denied unless a registered permission exists, the identity is
 * authenticated and not denied/stale, and RBAC has() grants it. Only THEN does the
 * policy/scope layer run, and it can only further restrict:
 *
 *   - A registered policy runs and may deny (it never re-authorizes — has() already passed).
 *   - With no policy, the outcome depends on the scope's enforcement kind
 *     (see {@see \Vortos\Authorization\Scope\ScopeEnforcement}):
 *       SelfSufficient (any/global) => allow, RBAC is authoritative;
 *       Containment (org/team/…)     => allow only if a scoped binding was enforced this request;
 *       Ownership (own, + unknown)   => deny, a policy is mandatory (fail closed).
 *   - A permission may opt into a mandatory policy (policyRequired) or declare that the
 *     relationship is enforced elsewhere (selfEnforced, audited as ExternallyEnforced).
 */
final class PolicyEngine
{
    public function __construct(
        private PolicyRegistryInterface $registry,
        private PermissionRegistryInterface $permissionRegistry,
        private PermissionResolverInterface $resolver,
        private EmergencyDenyListInterface $denyList,
        private AuthorizationVersionStoreInterface $versionStore,
        private RoleVoter $roleVoter,
        private bool $authzVersionCheck = true,
        private bool $breakGlassBypass = false,
        private string $breakGlassRole = 'ROLE_SUPER_ADMIN',
        private ?ScopedPermissionStoreInterface $scopedPermissions = null,
        private ?AuthorizationTracer $tracer = null,
        private readonly ?RequestAuthzVersionProvider $authzVersionProvider = null,
        ?ScopeEnforcementClassifier $scopeClassifier = null,
        ?OwnerResolverRegistry $ownerResolver = null,
    ) {
        // Default to an empty classifier: framework defaults apply and every other
        // scope falls to Ownership (fail closed).
        $this->scopeClassifier = $scopeClassifier ?? new ScopeEnforcementClassifier();
        $this->ownerResolver = $ownerResolver ?? new OwnerResolverRegistry();
    }

    private readonly ScopeEnforcementClassifier $scopeClassifier;
    private readonly OwnerResolverRegistry $ownerResolver;

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
        return $this->decide($identity, $permission, $resource)->allowed();
    }

    /**
     * @param array<string, string> $scopes scope name => scope id
     */
    public function canScoped(
        UserIdentityInterface $identity,
        string $permission,
        array $scopes,
        ScopeMode|string $scopeMode = ScopeMode::All,
        mixed $resource = null,
    ): bool {
        return $this->decideScoped($identity, $permission, $scopes, $scopeMode, $resource)->allowed();
    }

    public function decide(
        UserIdentityInterface $identity,
        string $permission,
        mixed $resource = null,
    ): AuthorizationDecision {
        return $this->decideWithOptions($identity, $permission, $resource, allowBreakGlassBypass: true);
    }

    /**
     * @param array<string, string> $scopes scope name => scope id
     */
    public function decideScoped(
        UserIdentityInterface $identity,
        string $permission,
        array $scopes,
        ScopeMode|string $scopeMode = ScopeMode::All,
        mixed $resource = null,
    ): AuthorizationDecision {
        return $this->decideWithOptions(
            $identity,
            $permission,
            $resource,
            allowBreakGlassBypass: true,
            scopes: $scopes,
            scopeMode: $this->normalizeScopeMode($scopeMode),
        );
    }

    public function canCritical(
        UserIdentityInterface $identity,
        string $permission,
        mixed $resource = null,
    ): bool {
        return $this->decideCritical($identity, $permission, $resource)->allowed();
    }

    public function decideCritical(
        UserIdentityInterface $identity,
        string $permission,
        mixed $resource = null,
    ): AuthorizationDecision {
        return $this->decideWithOptions($identity, $permission, $resource, allowBreakGlassBypass: false);
    }

    public function authorizeCritical(
        UserIdentityInterface $identity,
        string $permission,
        mixed $resource = null,
    ): void {
        $decision = $this->decideCritical($identity, $permission, $resource);

        if ($decision->allowed()) {
            return;
        }

        if ($decision->reason() === AuthorizationDecisionReason::Unauthenticated->value) {
            throw AccessDeniedException::unauthenticated($permission);
        }

        throw AccessDeniedException::forbidden($identity->id(), $permission);
    }

    private function decideWithOptions(
        UserIdentityInterface $identity,
        string $permission,
        mixed $resource,
        bool $allowBreakGlassBypass,
        ?array $scopes = null,
        ScopeMode $scopeMode = ScopeMode::All,
    ): AuthorizationDecision {
        $span = $this->tracer?->decision('authorization.decision', [
            'authorization.permission' => $permission,
            'authorization.user_id_hash' => $identity->isAuthenticated() ? TelemetryLabels::userHash($identity->id()) : null,
            'authorization.scoped' => $scopes !== null,
            'authorization.critical' => !$allowBreakGlassBypass,
        ]);

        try {
            $decision = $this->evaluateDecision(
                $identity,
                $permission,
                $resource,
                $allowBreakGlassBypass,
                $scopes,
                $scopeMode,
            );
            $span?->addAttribute('authorization.allowed', $decision->allowed());
            $span?->addAttribute('authorization.reason', $decision->reason());
            $span?->setStatus($decision->allowed() ? 'ok' : 'error');

            return $decision;
        } catch (\Throwable $e) {
            $span?->recordException($e);
            $span?->setStatus('error');
            throw $e;
        } finally {
            $span?->end();
        }
    }

    private function evaluateDecision(
        UserIdentityInterface $identity,
        string $permission,
        mixed $resource,
        bool $allowBreakGlassBypass,
        ?array $scopes = null,
        ScopeMode $scopeMode = ScopeMode::All,
    ): AuthorizationDecision {
        try {
            [$resourceType, $action, $scope] = $this->parsePermission($permission);
        } catch (\InvalidArgumentException) {
            return AuthorizationDecision::deny(AuthorizationDecisionReason::InvalidPermissionFormat, $permission);
        }

        if (!$this->permissionRegistry->exists($permission)) {
            return AuthorizationDecision::deny(AuthorizationDecisionReason::UnknownPermission, $permission);
        }

        if (!$identity->isAuthenticated()) {
            return AuthorizationDecision::deny(AuthorizationDecisionReason::Unauthenticated, $permission);
        }

        if ($this->denyList->isDenied($identity->id())) {
            return AuthorizationDecision::deny(AuthorizationDecisionReason::EmergencyDenied, $permission);
        }

        if ($this->hasStaleAuthorizationVersion($identity)) {
            return AuthorizationDecision::deny(AuthorizationDecisionReason::StaleToken, $permission);
        }

        if ($allowBreakGlassBypass && $this->canUseBreakGlassBypass($identity, $permission)) {
            return AuthorizationDecision::allow($permission);
        }

        $resolved = $this->resolver->resolve($identity);

        if (!$resolved->has($permission)) {
            return AuthorizationDecision::deny(AuthorizationDecisionReason::MissingPermission, $permission);
        }

        // Capture whether the scoped store proved a containment relationship THIS request.
        // A non-null $scopes that fails the check is an outright denial; if it passes we
        // remember that fact for the no-policy decision below.
        $scopeEnforced = false;

        if ($scopes !== null) {
            $scopeEnforced = $this->passesScopedCheck($identity, $permission, $scopes, $scopeMode);

            if (!$scopeEnforced) {
                return AuthorizationDecision::deny(AuthorizationDecisionReason::ScopedPermissionDenied, $permission);
            }
        }

        // A registered policy is authoritative for refinement: it runs and may only
        // further restrict (it can never re-authorize — we are already past has()).
        if ($this->registry->hasForResource($resourceType)) {
            $policy = $this->registry->findForResource($resourceType);
            $context = new AuthorizationContext($identity, $resolved, $this->roleVoter, $scope, $this->ownerResolver);

            $result = $policy->can($context, $action, $scope, $resource);

            if ($result instanceof PolicyDecision) {
                return $result->allowed
                    ? AuthorizationDecision::allow($permission)
                    : AuthorizationDecision::deny(
                        $result->reason ?? AuthorizationDecisionReason::ResourceDenied->value,
                        $permission,
                    );
            }

            if (!$result) {
                return AuthorizationDecision::deny(AuthorizationDecisionReason::ResourceDenied, $permission);
            }

            return AuthorizationDecision::allow($permission);
        }

        // No policy registered. RBAC has() has already passed; decide by what the scope
        // actually promises and whether that promise was kept.
        return $this->decideWithoutPolicy($permission, $scope, $scopeEnforced);
    }

    /**
     * Scope-aware default when no resource policy exists. Safe-by-default: only scopes
     * the engine can genuinely prove are allowed; everything else fails closed.
     */
    private function decideWithoutPolicy(
        string $permission,
        string $scope,
        bool $scopeEnforced,
    ): AuthorizationDecision {
        $metadata = $this->permissionRegistry->metadata($permission);

        // Explicit opt-in to mandatory policy wins over everything (defense in depth).
        if ($metadata?->policyRequired === true) {
            return AuthorizationDecision::deny(AuthorizationDecisionReason::PolicyRequired, $permission);
        }

        // Declared, audited handler-level enforcement.
        if ($metadata?->selfEnforced === true) {
            return AuthorizationDecision::allow($permission, AuthorizationDecisionReason::ExternallyEnforced);
        }

        return match ($this->scopeClassifier->classify($scope)) {
            // RBAC fully expresses this scope — has() was enough.
            ScopeEnforcement::SelfSufficient => AuthorizationDecision::allow(
                $permission,
                AuthorizationDecisionReason::RbacAuthoritative,
            ),
            // Containment is satisfiable ONLY by the scoped store, and only if a matching
            // binding was actually enforced this request.
            ScopeEnforcement::Containment => $scopeEnforced
                ? AuthorizationDecision::allow($permission, AuthorizationDecisionReason::ScopeSatisfied)
                : AuthorizationDecision::deny(AuthorizationDecisionReason::PolicyOrScopeRequired, $permission),
            // Ownership can never be proven without a policy — not even by a containment
            // binding. This is the privilege-escalation guard for `.own` permissions.
            ScopeEnforcement::Ownership => AuthorizationDecision::deny(
                AuthorizationDecisionReason::PolicyOrScopeRequired,
                $permission,
            ),
        };
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
        $decision = $this->decide($identity, $permission, $resource);

        if ($decision->allowed()) {
            return;
        }

        if ($decision->reason() === AuthorizationDecisionReason::Unauthenticated->value) {
            throw AccessDeniedException::unauthenticated($permission);
        }

        throw AccessDeniedException::forbidden($identity->id(), $permission);
    }

    /**
     * @throws \InvalidArgumentException If format is invalid
     * @return array{0: string, 1: string, 2: string}
     */
    private function parsePermission(string $permission): array
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

    private function hasStaleAuthorizationVersion(UserIdentityInterface $identity): bool
    {
        if (!$this->authzVersionCheck) {
            return false;
        }

        $tokenVersion = $this->authzVersionProvider?->get() ?? 0;

        return $tokenVersion < $this->versionStore->versionForUser($identity->id());
    }

    private function canUseBreakGlassBypass(UserIdentityInterface $identity, string $permission): bool
    {
        if (!$this->breakGlassBypass || !$this->roleVoter->hasRole($identity, $this->breakGlassRole)) {
            return false;
        }

        return $this->permissionRegistry->metadata($permission)?->bypassable === true;
    }

    /**
     * @param array<string, string> $scopes scope name => scope id
     */
    private function passesScopedCheck(
        UserIdentityInterface $identity,
        string $permission,
        array $scopes,
        ScopeMode $scopeMode,
    ): bool {
        if ($this->scopedPermissions === null || $scopes === []) {
            return false;
        }

        foreach ($scopes as $scopeName => $scopeId) {
            $hasPermission = $this->scopedPermissions->has($identity->id(), $scopeName, $scopeId, $permission);

            if ($scopeMode === ScopeMode::Any && $hasPermission) {
                return true;
            }

            if ($scopeMode === ScopeMode::All && !$hasPermission) {
                return false;
            }
        }

        return $scopeMode === ScopeMode::All;
    }

    private function normalizeScopeMode(ScopeMode|string $scopeMode): ScopeMode
    {
        if ($scopeMode instanceof ScopeMode) {
            return $scopeMode;
        }

        if (!defined(ScopeMode::class . '::' . $scopeMode)) {
            throw new \InvalidArgumentException(sprintf('Unknown scope mode "%s".', $scopeMode));
        }

        return constant(ScopeMode::class . '::' . $scopeMode);
    }
}
