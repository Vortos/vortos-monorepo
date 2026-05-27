<?php

declare(strict_types=1);

namespace Vortos\Ses\Exception;

final class OutboxWriteException extends \RuntimeException
{
    public static function forEmail(string $toAddress, \Throwable $previous): self
    {
        return new self(
            sprintf('Failed to write email to ses_outbox for recipient "%s": %s', $toAddress, $previous->getMessage()),
            0,
            $previous,
        );
    }
}

