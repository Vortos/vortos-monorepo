<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Scim\Sso\ClaimsRoleMapper;
use Vortos\Auth\Scim\Sso\ClaimsRoleMapping;

/**
 * Block 29.4 — SSO claims→role mapping tests.
 */
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
        // Both groups map to flags.admin
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
        $roles  = $mapper->mapGroupsToRoles(['product-admin']); // matches '.*-admin$'

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
        $roles  = $mapper->mapGroupsToRoles(['flags admins']); // different case

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

    public function test_unknown_display_name_returns_default(): void
    {
        $mapper = $this->makeMapper();
        $role   = $mapper->mapGroupDisplayNameToRole('unknown');

        $this->assertSame('flags.viewer', $role);
    }

    // -------------------------------------------------------------------------
    // Pattern validation
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
}
