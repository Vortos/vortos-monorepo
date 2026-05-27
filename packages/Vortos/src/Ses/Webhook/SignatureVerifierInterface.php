<?php

declare(strict_types=1);

namespace Vortos\Ses\Webhook;

use Vortos\Ses\Exception\WebhookVerificationException;

interface SignatureVerifierInterface
{
    /** @throws WebhookVerificationException */
    public function verify(array $payload): void;
}
