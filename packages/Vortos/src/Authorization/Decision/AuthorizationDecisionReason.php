<?php

declare(strict_types=1);

namespace Vortos\Authorization\Decision;

enum AuthorizationDecisionReason: string
{
    case Allowed = 'allowed';
    case Unauthenticated = 'unauthenticated';
    case StaleToken = 'stale_token';
    case EmergencyDenied = 'emergency_denied';
    case InvalidPermissionFormat = 'invalid_permission_format';
    case UnknownPermission = 'unknown_permission';
    case MissingPermission = 'missing_permission';
    case ScopedPermissionDenied = 'scoped_permission_denied';
    case ResourceDenied = 'resource_denied';

    // Scope-aware no-policy outcomes (Phase 2 model). RBAC has() has already passed
    // by the time any of these are produced.
    case RbacAuthoritative = 'rbac_authoritative';      // self-sufficient scope, no policy -> allow
    case ScopeSatisfied = 'scope_satisfied';            // containment scope enforced by scoped store -> allow
    case ExternallyEnforced = 'externally_enforced';    // permission declared selfEnforced -> allow (audited)
    case PolicyOrScopeRequired = 'policy_or_scope_required'; // relationship scope, unenforced, no policy -> deny
    case PolicyRequired = 'policy_required';            // permission declared policyRequired, no policy -> deny
}
