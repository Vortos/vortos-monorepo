<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Exception;

final class WebhookVerificationException extends \RuntimeException
{
    public static function invalidSignature(): self
    {
        return new self('SNS webhook signature verification failed.');
    }

    public static function untrustedCertUrl(string $url): self
    {
        return new self(sprintf(
            'SNS signing certificate URL "%s" is not a trusted amazonaws.com host.',
            $url,
        ));
    }

    public static function missingHeader(string $header): self
    {
        return new self(sprintf('SNS webhook request is missing required header: %s', $header));
    }
}
