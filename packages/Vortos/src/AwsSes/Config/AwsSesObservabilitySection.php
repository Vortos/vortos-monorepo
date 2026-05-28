<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Config;

enum AwsSesObservabilitySection: string
{
    case Send = 'send';
    case Outbox = 'outbox';
    case Webhook = 'webhook';
    case Suppression = 'suppression';
    case RateLimit = 'rate_limit';
    case Audit = 'audit';
}
