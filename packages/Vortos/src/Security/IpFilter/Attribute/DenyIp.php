<?php

declare(strict_types=1);

namespace Vortos\Security\IpFilter\Attribute;

use Attribute;

/**
 * Blocks a controller or action for the listed IP ranges.
 * Denied IPs receive 403 for this route regardless of global config.
 *
 * @param list<string> $cidrs CIDR ranges or exact IPs to deny.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class DenyIp
{
    /** @param list<string> $cidrs */
    public function __construct(
        public readonly array $cidrs,
    ) {}
}
