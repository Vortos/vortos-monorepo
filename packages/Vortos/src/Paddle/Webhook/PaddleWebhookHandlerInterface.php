<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook;

use Vortos\Paddle\Webhook\Event\PaddleWebhookEvent;

interface PaddleWebhookHandlerInterface
{
    public function handles(): string;

    public function handle(PaddleWebhookEvent $event): void;
}
