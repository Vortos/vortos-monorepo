<?php

declare(strict_types=1);

namespace Vortos\Alerts\RateLimit;

final readonly class OutboundRateLimitConfig
{
    /**
     * @param int $perTenantPerHour  Max notifications per tenant per sliding hour (0 = unlimited)
     * @param int $globalPerHour     Max notifications globally per sliding hour (0 = unlimited)
     * @param array<string, int> $perChannelKindPerHour  Per-channel-kind overrides (e.g., 'ses' => 20 for SMS-class channels)
     */
    public function __construct(
        public int $perTenantPerHour = 100,
        public int $globalPerHour = 1000,
        public array $perChannelKindPerHour = [],
    ) {}
}
