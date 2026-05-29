<?php

declare(strict_types=1);

namespace Vortos\Paddle\Config;

enum PaddleObservabilitySection: string
{
    case ApiClient       = 'api_client';
    case WebhookIngestion = 'webhook_ingestion';
    case WebhookPayload  = 'webhook_payload';
    case Outbox          = 'outbox';
    case Checkout        = 'checkout';
    case PortalSession   = 'portal_session';
}
