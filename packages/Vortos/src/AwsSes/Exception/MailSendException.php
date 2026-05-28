<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Exception;

final class MailSendException extends \RuntimeException
{
    public static function fromSesError(string $errorCode, string $message, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('AWS SES send failed [%s]: %s', $errorCode, $message),
            0,
            $previous,
        );
    }

    public static function bothRegionsUnavailable(): self
    {
        return new self('Email delivery failed: both primary and fallback SES regions are unavailable.');
    }

    public static function noFromAddress(): self
    {
        return new self('Cannot send email: no from address configured. Set SES_FROM_ADDRESS or call Email::from().');
    }
}
