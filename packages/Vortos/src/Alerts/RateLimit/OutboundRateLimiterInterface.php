<?php

declare(strict_types=1);

namespace Vortos\Alerts\RateLimit;

interface OutboundRateLimiterInterface
{
    public function tryConsume(string $tenantId, string $channelKind): RateLimitDecision;
}
