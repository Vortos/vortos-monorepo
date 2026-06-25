<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Scim\Sso\ClaimsRoleMapper;
use Vortos\Auth\Scim\Sso\ClaimsRoleMapping;
use Vortos\Auth\Scim\Sso\RoleMappingException;

final class ClaimsRoleMapperTest extends TestCase
{
    private function makeMapper(): ClaimsRoleMapper
    {
        return new ClaimsRoleMapper([
            new ClaimsRoleMapping('Flags Admins', 'flags.admin'),
            new ClaimsRoleMapping('Flags Viewers', 'flags.viewer'),
            new ClaimsRoleMapping('flags:deploy', 'flags.deploy'),
            new ClaimsRoleMapping('.*-admin$', 'flags.admin', isPattern: true),
        ], defaultRole: 'flags.viewer');
    }

    // -------------------------------------------------------------------------
    // Groups → roles
    // -------------------------------------------------------------------------

    public function test_maps_known_group_to_role(): void
    {
        $mapper = $this->makeMapper();
        $roles  = $mapper->mapGroupsToRoles(['Flags Admins']);

        $this->assertContains('flags.admin', $roles);
    }

    public function test_multiple_groups_produce_multiple_roles(): void
    {
        $mapper = $this->makeMapper();
        $roles  = $mapper->mapGroupsToRoles(['Flags Admins', 'Flags Viewers']);

        $this->assertContains('flags.admin', $roles);
        $this->assertContains('flags.viewer', $roles);
    }

    public function test_deduplicates_roles(): void
    {
        $mapper = $this->makeMapper();
        $roles  = $mapper->mapGroupsToRoles(['Flags Admins', 'Flags Admins']);

        $this->assertSame(['flags.admin'], $roles);
    }

    public function test_unknown_group_falls_back_to_default_role(): void
    {
        $mapper = $this->makeMapper();
        $roles  = $mapper->mapGroupsToRoles(['unknown-group']);

        $this->assertSame(['flags.viewer'], $roles);
    }

    public function test_empty_groups_returns_default_role(): void
    {
        $mapper = $this->makeMapper();
        $roles  = $mapper->mapGroupsToRoles([]);

        $this->assertSame(['flags.viewer'], $roles);
    }

    public function test_no_default_role_and_unknown_group_returns_empty(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('Flags Admins', 'flags.admin'),
        ]);

        $roles = $mapper->mapGroupsToRoles(['unknown-group']);
        $this->assertSame([], $roles);
    }

    // -------------------------------------------------------------------------
    // Claims (OIDC scopes)
    // -------------------------------------------------------------------------

    public function test_maps_oidc_scope_claim_to_role(): void
    {
        $mapper = $this->makeMapper();
        $roles  = $mapper->mapClaimsToRoles(['flags:deploy']);

        $this->assertContains('flags.deploy', $roles);
    }

    // -------------------------------------------------------------------------
    // Pattern matching
    // -------------------------------------------------------------------------

    public function test_pattern_mapping_matches_regex(): void
    {
        $mapper = $this->makeMapper();
        $roles  = $mapper->mapGroupsToRoles(['product-admin']);

        $this->assertContains('flags.admin', $roles);
    }

    public function test_pattern_does_not_match_non_matching_group(): void
    {
        $mapper = new ClaimsRoleMapper([
            new ClaimsRoleMapping('.*admin.*', 'flags.admin', isPattern: true),
        ]);

        $roles = $mapper->mapGroupsToRoles(['viewer-group']);
        $this->assertSame([], $roles);
    }

    public function test_case_insensitive_matching(): void
    {
        $mapper = $this->makeMapper();
        $roles  = $mapper->mapGroupsToRoles(['flags admins']);

        $this->assertContains('flags.admin', $roles);
    }

    // -------------------------------------------------------------------------
    // Display name → single role (for SCIM group provisioning)
    // -------------------------------------------------------------------------

    public function test_group_display_name_maps_to_role(): void
    {
        $mapper = $this->makeMapper();
        $role   = $mapper->mapGroupDisplayNameToRole('Flags Admins');

        $this->assertSame('flags.admin', $role);
    }

    public function test_unknown_display_name_returns_null_not_default(): void
    {
        $mapper = $this->makeMapper();
        $role   = $mapper->mapGroupDisplayNameToRole('unknown');

        $this->assertNull($role, 'SCIM group provisioning must not fall back to defaultRole for unmatched groups');
    }

    // -------------------------------------------------------------------------
    // Pattern validation — #17 ReDoS protection
    // -------------------------------------------------------------------------

    public function test_pattern_over_length_limit_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClaimsRoleMapping(str_repeat('a', 201), 'flags.admin', isPattern: true);
    }

    public function test_short_pattern_is_accepted(): void
    {
        $mapping = new ClaimsRoleMapping('.*admin.*', 'flags.admin', isPattern: true);
        $this->assertTrue($mapping->matchesGroup('super-admin'));
    }

    public function test_invalid_regex_pattern_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid regex/i');
        new ClaimsRoleMapping('[invalid', 'flags.admin', isPattern: true);
    }

    public function test_catastrophic_backtracking_pattern_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/catastrophic backtracking/i');
        new ClaimsRoleMapping('(a+)+$', 'flags.admin', isPattern: true);
    }

    public function test_nested_quantifier_star_star_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClaimsRoleMapping('(.*)*', 'flags.admin', isPattern: true);
    }

    public function test_nested_quantifier_word_plus_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClaimsRoleMapping('(\w+)+', 'flags.admin', isPattern: true);
    }

    public function test_safe_pattern_with_quantifier_in_group_accepted(): void
    {
        $mapping = new ClaimsRoleMapping('group-[a-z]+', 'flags.viewer', isPattern: true);
        $this->assertTrue($mapping->matchesGroup('group-admins'));
    }

    public function test_backtrack_limit_enforced_at_runtime(): void
    {
        $n = 25;
        $pattern = str_repeat('a?', $n) . str_repeat('a', $n);
        $mapping = new ClaimsRoleMapping($pattern, 'flags.admin', isPattern: true);

        $this->expectException(RoleMappingException::class);
        $mapping->matchesGroup(str_repeat('a', $n));
    }

    public function test_mapper_returns_empty_roles_on_pattern_failure(): void
    {
        $n = 25;
        $pattern = str_repeat('a?', $n) . str_repeat('a', $n);
        $mapping = new ClaimsRoleMapping($pattern, 'flags.admin', isPattern: true);

        $mapper = new ClaimsRoleMapper([$mapping], defaultRole: 'flags.viewer');
        $roles = $mapper->mapGroupsToRoles([str_repeat('a', $n)]);

        $this->assertSame([], $roles, 'Pattern failure must fail closed — no roles granted');
    }

    public function test_mapper_claims_returns_empty_on_pattern_failure(): void
    {
        $n = 25;
        $pattern = str_repeat('a?', $n) . str_repeat('a', $n);
        $mapping = new ClaimsRoleMapping($pattern, 'flags.admin', isPattern: true);

        $mapper = new ClaimsRoleMapper([$mapping]);
        $roles = $mapper->mapClaimsToRoles([str_repeat('a', $n)]);

        $this->assertSame([], $roles);
    }

    public function test_mapper_display_name_returns_null_on_pattern_failure(): void
    {
        $n = 25;
        $pattern = str_repeat('a?', $n) . str_repeat('a', $n);
        $mapping = new ClaimsRoleMapping($pattern, 'flags.admin', isPattern: true);

        $mapper = new ClaimsRoleMapper([$mapping]);
        $result = $mapper->mapGroupDisplayNameToRole(str_repeat('a', $n));

        $this->assertNull($result);
    }

    public function test_non_pattern_mapping_unaffected_by_redos_protection(): void
    {
        $mapping = new ClaimsRoleMapping('(a+)+$', 'flags.admin', isPattern: false);
        $this->assertFalse($mapping->matchesGroup('test'));
        $this->assertTrue($mapping->matchesGroup('(a+)+$'));
    }
}
