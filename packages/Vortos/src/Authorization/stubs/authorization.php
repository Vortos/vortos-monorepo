<?php

declare(strict_types=1);

use Vortos\Authorization\DependencyInjection\VortosAuthorizationConfig;

// Infrastructure (storage backend, adapter) is chosen via ENV.
// This file controls authorization behaviour: role hierarchy, bypass rules,
// and tracing verbosity.
//
// For per-environment overrides create config/{env}/authorization.php.

return static function (VortosAuthorizationConfig $config): void {
    $config
        // Role inheritance hierarchy.
        //
        // Format: ['PARENT_ROLE' => ['CHILD_ROLE', ...]]
        //
        // A user that has PARENT_ROLE automatically passes permission checks
        // for all listed child roles, recursively through the hierarchy.
        // Empty array = no inheritance (all roles are independent).
        ->roleHierarchy([
            // Example multi-tier hierarchy:
            // 'ROLE_SUPER_ADMIN'      => ['ROLE_ADMIN'],
            // 'ROLE_ADMIN'            => ['ROLE_MANAGER'],
            // 'ROLE_MANAGER'          => ['ROLE_USER'],
        ])

        // Validate that permission definitions carry a version stamp.
        // Set to false only when migrating a legacy system without versioned perms.
        // ->authzVersionCheck(false)
    ;

    // Break-glass bypass — a designated super-admin role skips ALL permission checks.
    //
    // Only enable for genuine break-glass emergency access. Granting this role to a
    // service account or admin user bypasses every policy in the system.
    //
    // $config
    //     ->breakGlassBypass(true)
    //     ->breakGlassRole('ROLE_SUPER_ADMIN')
    // ;

    // Trace authorization decisions to the security log channel.
    // Useful for debugging RBAC issues in staging — avoid in prod (high volume).
    //
    // $config
    //     ->traceDecisions(true)   // log every Allow/Deny decision with reason
    //     ->traceResolver(true)    // log policy resolution steps
    //     ->traceAdminMutations(true) // log role/permission mutations
    // ;
};
