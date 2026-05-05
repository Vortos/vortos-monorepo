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
    case PolicyNotFound = 'policy_not_found';
    case ResourceDenied = 'resource_denied';
}
