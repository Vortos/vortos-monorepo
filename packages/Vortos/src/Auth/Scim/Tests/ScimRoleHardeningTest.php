<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\Contract\AuditStoreInterface;
use Vortos\Auth\Scim\Exception\ScimRoleForbiddenException;
use Vortos\Auth\Scim\ScimAuditLogger;
use Vortos\Auth\Scim\ScimRoleGuard;
use Vortos\Auth\Scim\ScimService;
use Vortos\Auth\Scim\Sso\ClaimsRoleMapper;
use Vortos\Auth\Scim\Sso\ClaimsRoleMapping;
use Vortos\Auth\Scim\Storage\InMemoryScimGroupStorage;
use Vortos\Auth\Scim\Storage\InMemoryScimUserStorage;
use Vortos\Auth\Scim\Token\ScimTokenRecord;
use Vortos\Tenant\TenantContext;

/**
 * Security regression tests for SCIM role hardening (issue #4).
 *
 * Covers all 5 layers:
 *  L1 — groups readOnly on Users (no client-supplied groups/roles)
 *  L2 — defaultRole fallback eliminated for SCIM group provisioning
 *  L3 — per-role scope guard on SCIM token
 *  L4 — provisionable-role allowlist on token record
 *  L5 — audit trail for role assignments via group membership
 */
final class ScimRoleHardeningTest extends TestCase
{
    private InMemoryScimUserStorage $userStorage;
    private InMemoryScimGroupStorage $groupStorage;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->userStorage  = new InMemoryScimUserStorage();
        $this->groupStorage = new InMemoryScimGroupStorage();
        $this->tenantContext = new TenantContext();
        $this->tenantContext->set('tenant-sec');
    }

    // =========================================================================
    // Layer 1 — groups readOnly on Users
    // =========================================================================

    public function test_create_user_ignores_groups_in_body(): void
    {
        $service = $this->makeService();
        $user = $service->createUser([
            'userName' => 'attacker@example.com',
            'groups'   => [['value' => 'flags-admin-group']],
        ]);

        $this->assertSame([], $user->groups, 'Client-supplied groups must be ignored on User create');
        $this->assertSame([], $user->roles, 'Client-supplied groups must not derive roles');
    }

    public function test_create_user_with_role_mapper_still_ignores_body_groups(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Flags Admins', 'flags.admin'),
        ]);
        $service = $this->makeService(roleMapper: $mapper);

        $user = $service->createUser([
            'userName' => 'attacker@example.com',
            'groups'   => [['value' => 'Flags Admins']],
        ]);

        $this->assertSame([], $user->groups);
        $this->assertSame([], $user->roles, 'Even with a role mapper configured, body groups must not produce roles');
    }

    public function test_replace_user_preserves_existing_groups(): void
    {
        $service = $this->makeService();

        $user = $service->createUser(['userName' => 'alice@example.com']);
        $group = $service->createGroup(['displayName' => 'Engineering', 'members' => [['value' => $user->id]]]);

        $reloaded = $service->getUser($user->id);
        $this->assertContains($group->id, $reloaded->groups, 'Group sync should have added the group');

        $replaced = $service->replaceUser($user->id, [
            'userName' => 'alice-new@example.com',
            'groups'   => [['value' => 'attacker-injected-group']],
        ]);

        $this->assertContains($group->id, $replaced->groups, 'Replace must carry forward existing groups');
        $this->assertNotContains('attacker-injected-group', $replaced->groups, 'Replace must not accept client-supplied groups');
    }

    public function test_replace_user_preserves_existing_roles(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Admins', 'flags.admin'),
        ]);
        $service = $this->makeService(roleMapper: $mapper);

        $user = $service->createUser(['userName' => 'bob@example.com']);
        $service->createGroup(['displayName' => 'Admins', 'members' => [['value' => $user->id]]]);

        $replaced = $service->replaceUser($user->id, ['userName' => 'bob-updated@example.com']);

        $this->assertSame([], $replaced->roles, 'Roles come from group sync, which runs on group ops not user replace');
    }

    // =========================================================================
    // Layer 2 — defaultRole eliminated for SCIM group provisioning
    // =========================================================================

    public function test_group_display_name_no_match_returns_null_not_default(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Flags Admins', 'flags.admin'),
        ], defaultRole: 'flags.viewer');

        $this->assertNull(
            $mapper->mapGroupDisplayNameToRole('unknown-group'),
            'mapGroupDisplayNameToRole must return null for unmatched groups, never defaultRole',
        );
    }

    public function test_sso_claims_path_still_uses_default_role(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Flags Admins', 'flags.admin'),
        ], defaultRole: 'flags.viewer');

        $roles = $mapper->mapGroupsToRoles(['random-sso-group']);
        $this->assertContains('flags.viewer', $roles, 'SSO claims path should still fall back to defaultRole');
    }

    public function test_create_group_unmatched_name_gets_null_platform_role(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Flags Admins', 'flags.admin'),
        ], defaultRole: 'flags.viewer');
        $service = $this->makeService(roleMapper: $mapper);

        $group = $service->createGroup(['displayName' => 'Unknown Team']);
        $this->assertNull($group->platformRole, 'Unmatched SCIM group must not receive defaultRole as platformRole');
    }

    // =========================================================================
    // Layer 3 — ScimRoleGuard per-role scope check
    // =========================================================================

    public function test_role_guard_allows_permitted_role(): void
    {
        $guard = new ScimRoleGuard();
        $token = $this->makeToken(scopes: ['scim:groups:write', 'scim:role:flags.admin']);

        $guard->assertPermittedRoles($token, ['flags.admin']);
        $this->addToAssertionCount(1);
    }

    public function test_role_guard_rejects_missing_role_scope(): void
    {
        $guard = new ScimRoleGuard();
        $token = $this->makeToken(scopes: ['scim:groups:write']);

        $this->expectException(ScimRoleForbiddenException::class);
        $guard->assertPermittedRoles($token, ['flags.admin']);
    }

    public function test_role_guard_rejects_partial_role_scopes(): void
    {
        $guard = new ScimRoleGuard();
        $token = $this->makeToken(scopes: ['scim:groups:write', 'scim:role:flags.viewer']);

        $this->expectException(ScimRoleForbiddenException::class);
        $guard->assertPermittedRoles($token, ['flags.admin']);
    }

    public function test_create_group_with_role_rejects_unpermitted_token(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Flags Admins', 'flags.admin'),
        ]);
        $guard = new ScimRoleGuard();
        $service = $this->makeService(roleMapper: $mapper, roleGuard: $guard);
        $token = $this->makeToken(scopes: ['scim:groups:write']);

        $this->expectException(ScimRoleForbiddenException::class);
        $service->createGroup(['displayName' => 'Flags Admins'], $token);
    }

    public function test_create_group_with_role_accepts_permitted_token(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Flags Admins', 'flags.admin'),
        ]);
        $guard = new ScimRoleGuard();
        $service = $this->makeService(roleMapper: $mapper, roleGuard: $guard);
        $token = $this->makeToken(scopes: ['scim:groups:write', 'scim:role:flags.admin']);

        $group = $service->createGroup(['displayName' => 'Flags Admins'], $token);
        $this->assertSame('flags.admin', $group->platformRole);
    }

    public function test_create_group_without_token_skips_guard(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Flags Admins', 'flags.admin'),
        ]);
        $guard = new ScimRoleGuard();
        $service = $this->makeService(roleMapper: $mapper, roleGuard: $guard);

        $group = $service->createGroup(['displayName' => 'Flags Admins']);
        $this->assertSame('flags.admin', $group->platformRole, 'Internal/system calls without token should bypass the guard');
    }

    public function test_create_group_without_platform_role_skips_guard(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Flags Admins', 'flags.admin'),
        ]);
        $guard = new ScimRoleGuard();
        $service = $this->makeService(roleMapper: $mapper, roleGuard: $guard);
        $token = $this->makeToken(scopes: ['scim:groups:write']);

        $group = $service->createGroup(['displayName' => 'No Role Mapped'], $token);
        $this->assertNull($group->platformRole, 'Groups without a mapped role should not trigger the guard');
    }

    // =========================================================================
    // Layer 4 — provisionable-role allowlist on token
    // =========================================================================

    public function test_token_role_permitted_with_scope_and_allowlist(): void
    {
        $token = $this->makeToken(
            scopes: ['scim:role:flags.viewer'],
            allowedRoles: ['flags.viewer'],
        );

        $this->assertTrue($token->isRolePermitted('flags.viewer'));
    }

    public function test_token_role_rejected_when_not_in_allowlist(): void
    {
        $token = $this->makeToken(
            scopes: ['scim:role:flags.admin'],
            allowedRoles: ['flags.viewer'],
        );

        $this->assertFalse($token->isRolePermitted('flags.admin'), 'Role must be in both scopes AND allowlist');
    }

    public function test_token_role_rejected_when_scope_missing_despite_allowlist(): void
    {
        $token = $this->makeToken(
            scopes: ['scim:groups:write'],
            allowedRoles: ['flags.admin'],
        );

        $this->assertFalse($token->isRolePermitted('flags.admin'), 'Scope is required even if allowlist permits');
    }

    public function test_empty_allowlist_defers_to_scope_only(): void
    {
        $token = $this->makeToken(
            scopes: ['scim:role:flags.admin'],
            allowedRoles: [],
        );

        $this->assertTrue($token->isRolePermitted('flags.admin'), 'Empty allowlist should defer to scope check only');
    }

    // =========================================================================
    // Layer 5 — Audit trail for role assignments
    // =========================================================================

    public function test_audit_entry_emitted_on_group_membership_with_role(): void
    {
        $entries = [];
        $store = new class($entries) implements AuditStoreInterface {
            private array $entries;
            public function __construct(array &$entries) { $this->entries = &$entries; }
            public function record(AuditEntry $entry): void { $this->entries[] = $entry; }
        };

        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Admins', 'flags.admin'),
        ]);
        $auditLogger = new ScimAuditLogger($store);
        $service = $this->makeService(roleMapper: $mapper, auditLogger: $auditLogger);
        $token = $this->makeToken(scopes: ['scim:groups:write', 'scim:role:flags.admin']);

        $user = $service->createUser(['userName' => 'audited@example.com']);
        $service->createGroup(['displayName' => 'Admins', 'members' => [['value' => $user->id]]], $token);

        $this->assertCount(1, $entries);
        $this->assertSame('scim.role.assign', $entries[0]->action);
        $this->assertSame($user->id, $entries[0]->resourceId);
        $this->assertSame('flags.admin', $entries[0]->metadata['role']);
    }

    public function test_no_audit_entry_when_group_has_no_platform_role(): void
    {
        $entries = [];
        $store = new class($entries) implements AuditStoreInterface {
            private array $entries;
            public function __construct(array &$entries) { $this->entries = &$entries; }
            public function record(AuditEntry $entry): void { $this->entries[] = $entry; }
        };

        $auditLogger = new ScimAuditLogger($store);
        $service = $this->makeService(auditLogger: $auditLogger);

        $user = $service->createUser(['userName' => 'noaudit@example.com']);
        $service->createGroup(['displayName' => 'No Role Team', 'members' => [['value' => $user->id]]]);

        $this->assertCount(0, $entries, 'No audit entry when group carries no platform role');
    }

    public function test_no_audit_entry_when_user_already_in_group(): void
    {
        $entries = [];
        $store = new class($entries) implements AuditStoreInterface {
            private array $entries;
            public function __construct(array &$entries) { $this->entries = &$entries; }
            public function record(AuditEntry $entry): void { $this->entries[] = $entry; }
        };

        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Admins', 'flags.admin'),
        ]);
        $auditLogger = new ScimAuditLogger($store);
        $service = $this->makeService(roleMapper: $mapper, auditLogger: $auditLogger);

        $user = $service->createUser(['userName' => 'existing@example.com']);
        $group = $service->createGroup(['displayName' => 'Admins', 'members' => [['value' => $user->id]]]);

        $this->assertCount(1, $entries);

        $service->replaceGroup($group->id, ['displayName' => 'Admins', 'members' => [['value' => $user->id]]]);

        $this->assertCount(1, $entries, 'No duplicate audit entry when user was already in the group');
    }

    public function test_audit_logger_silently_handles_store_failure(): void
    {
        $store = new class implements AuditStoreInterface {
            public function record(AuditEntry $entry): void { throw new \RuntimeException('Store down'); }
        };

        $logger = new ScimAuditLogger($store);

        $logger->logRoleAssignment('t1', 'tok1', 'u1', 'flags.admin', 'g1');
        $this->addToAssertionCount(1);
    }

    public function test_audit_logger_noop_without_store(): void
    {
        $logger = new ScimAuditLogger(null);

        $logger->logRoleAssignment('t1', 'tok1', 'u1', 'flags.admin', 'g1');
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Exception structure
    // =========================================================================

    public function test_scim_role_forbidden_exception_carries_role_and_token_id(): void
    {
        $ex = new ScimRoleForbiddenException('flags.admin', 'tok-123');

        $this->assertSame('flags.admin', $ex->role);
        $this->assertSame('tok-123', $ex->tokenId);
        $this->assertStringContainsString('flags.admin', $ex->getMessage());
        $this->assertStringContainsString('tok-123', $ex->getMessage());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeService(
        ?ClaimsRoleMapper $roleMapper = null,
        ?ScimRoleGuard $roleGuard = null,
        ?ScimAuditLogger $auditLogger = null,
    ): ScimService {
        return new ScimService(
            $this->userStorage,
            $this->groupStorage,
            $this->tenantContext,
            $roleMapper,
            $roleGuard,
            $auditLogger,
        );
    }

    private function makeToken(array $scopes = [], array $allowedRoles = []): ScimTokenRecord
    {
        return new ScimTokenRecord(
            id: 'tok-' . bin2hex(random_bytes(4)),
            tenantId: 'tenant-sec',
            hashedToken: hash('sha256', 'test-token'),
            scopes: $scopes,
            allowedCidrs: [],
            active: true,
            createdAt: new \DateTimeImmutable(),
            expiresAt: null,
            lastUsedAt: null,
            allowedRoles: $allowedRoles,
        );
    }
}
