<?php

declare(strict_types=1);

namespace Vortos\Auth\ApiKey\Attribute;

use Attribute;

/**
 * Requires a valid API key on an endpoint.
 *
 * Used for machine-to-machine (M2M) authentication — server integrations,
 * CI/CD pipelines, internal services, and SDK clients that cannot use JWT.
 *
 * API keys are sent via the Authorization header: "ApiKey <key>"
 *
 * ## Scopes
 *
 * API keys carry a list of granted scopes. The #[RequiresApiKey] attribute
 * can optionally require one or more scopes — the request is rejected if the
 * key does not have all required scopes.
 *
 * @param list<string> $scopes Required scopes. Empty = any valid API key is accepted.
 *
 * Example:
 *
 *   #[RequiresApiKey(scopes: ['webhooks:write', 'athletes:read'])]
 *   class ExternalSyncController { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class RequiresApiKey
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly array $scopes = [],
    ) {}
}
