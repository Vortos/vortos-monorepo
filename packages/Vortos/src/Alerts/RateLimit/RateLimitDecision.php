<?php

declare(strict_types=1);

namespace Vortos\Alerts\RateLimit;

enum RateLimitDecision: string
{
    case Allowed = 'allowed';
    case TenantExhausted = 'tenant_exhausted';
    case GlobalExhausted = 'global_exhausted';
}
