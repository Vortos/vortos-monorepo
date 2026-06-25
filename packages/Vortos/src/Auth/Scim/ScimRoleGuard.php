<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim;

use Vortos\Auth\Scim\Exception\ScimRoleForbiddenException;
use Vortos\Auth\Scim\Token\ScimTokenRecord;

final class ScimRoleGuard
{
    /**
     * @param list<string> $roles Platform role slugs to verify
     * @throws ScimRoleForbiddenException if any role is not permitted by the token
     */
    public function assertPermittedRoles(ScimTokenRecord $token, array $roles): void
    {
        foreach ($roles as $role) {
            if (!$token->isRolePermitted($role)) {
                throw new ScimRoleForbiddenException($role, $token->id);
            }
        }
    }
}
