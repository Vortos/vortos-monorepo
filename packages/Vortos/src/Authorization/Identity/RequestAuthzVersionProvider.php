<?php

declare(strict_types=1);

namespace Vortos\Authorization\Identity;

use Vortos\Cache\Adapter\ArrayAdapter;

/**
 * Provides the authorization version from the current request context.
 *
 * In HTTP context: populated by AuthMiddleware after JWT validation.
 * In CLI context: populated by AuthCommandIdentityFactory when building a simulation identity.
 *
 * Returns null when no value has been set — PolicyEngine treats null as version 0.
 */
final class RequestAuthzVersionProvider
{
    public function __construct(private ArrayAdapter $arrayAdapter) {}

    public function get(): ?int
    {
        $value = $this->arrayAdapter->get('auth:authz_version');

        return $value !== null ? (int) $value : null;
    }
}
