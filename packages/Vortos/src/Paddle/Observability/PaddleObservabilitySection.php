<?php

declare(strict_types=1);

namespace Vortos\Paddle\Observability;

enum PaddleObservabilitySection
{
    case ApiClient;
    case WebhookIngestion;
    case Outbox;
    case Checkout;
    case PortalSession;
}
