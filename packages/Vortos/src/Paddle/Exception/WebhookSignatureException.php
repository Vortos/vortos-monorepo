<?php

declare(strict_types=1);

namespace Vortos\Paddle\Exception;

final class WebhookSignatureException extends PaddleWebhookException
{
    public static function missingHeader(): self
    {
        return new self('Paddle-Signature header is missing.');
    }

    public static function malformedHeader(string $header): self
    {
        return new self(sprintf('Paddle-Signature header is malformed: %s', $header));
    }

    public static function invalidHmac(): self
    {
        return new self('Paddle webhook HMAC signature does not match.');
    }

    public static function noValidSecret(): self
    {
        return new self('Paddle webhook signature could not be verified with any configured secret.');
    }
}
