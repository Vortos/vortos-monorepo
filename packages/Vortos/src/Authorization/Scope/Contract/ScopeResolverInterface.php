<?php
declare(strict_types=1);

namespace Vortos\Authorization\Scope\Contract;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the scope value from the current request.
 *
 * Auto-discovered — just implement this interface.
 * The scope name returned by getScopeName() matches the
 * scope parameter in #[RequiresPermission(..., scope: 'org')].
 *
 * Example:
 *   class OrgScopeResolver implements ScopeResolverInterface
 *   {
 *       public function getScopeName(): string { return 'org'; }
 *       public function resolveScope(Request $request): string
 *       {
 *           return $request->attributes->get('org_id')
 *               ?? throw new MissingScopeException();
 *       }
 *   }
 */
interface ScopeResolverInterface
{
    public function getScopeName(): string;
    public function resolveScope(Request $request): string;
}
