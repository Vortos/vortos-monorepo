<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Exception;

final class SuppressionListException extends \RuntimeException
{
    /**
     * @param \Vortos\AwsSes\ValueObject\EmailAddress[] $addresses
     */
    public static function forAddresses(array $addresses): self
    {
        $list = implode(', ', array_map(fn($a) => $a->address(), $addresses));
        return new self(sprintf(
            'Email send blocked: the following recipients are suppressed: %s',
            $list,
        ));
    }

    /**
     * @param \Vortos\AwsSes\ValueObject\EmailAddress[] $addresses
     */
    public static function allRecipientsSuppressed(array $addresses): self
    {
        $list = implode(', ', array_map(fn($a) => $a->address(), $addresses));
        return new self(sprintf(
            'Email send aborted: all recipients are suppressed and skip mode removed them all: %s',
            $list,
        ));
    }
}
