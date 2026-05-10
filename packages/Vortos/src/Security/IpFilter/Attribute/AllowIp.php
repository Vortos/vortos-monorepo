<?php

declare(strict_types=1);

namespace Vortos\Security\IpFilter\Attribute;

use Attribute;

/**
 * Restricts a controller or action to only the listed IP ranges.
 * All other IPs are denied (403) for this route regardless of global allowlist.
 *
 * Example — admin panel accessible only from office IP:
 *
 *   #[AllowIp(['203.0.113.0/24', '198.51.100.50'])]
 *   class AdminController { ... }
 *
 * @param list<string> $cidrs CIDR ranges or exact IPs.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class AllowIp
{
    /** @param list<string> $cidrs */
    public function __construct(
        public readonly array $cidrs,
    ) {}
}
