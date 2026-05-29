<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook;

use Vortos\Paddle\Exception\WebhookReplayException;
use Vortos\Paddle\Exception\WebhookSignatureException;

interface WebhookVerifierInterface
{
    /**
     * @throws WebhookSignatureException
     * @throws WebhookReplayException
     */
    public function verify(string $rawBody, string $signatureHeader): void;
}
