<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Webhook;

use Vortos\AwsSes\Exception\WebhookVerificationException;

interface SignatureVerifierInterface
{
    /** @throws WebhookVerificationException */
    public function verify(array $payload): void;
}
