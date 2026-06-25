<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Exception;

final class UnknownEmitterException extends \InvalidArgumentException
{
    /**
     * @param list<string> $available
     */
    public static function forKey(string $key, array $available): self
    {
        return new self(sprintf(
            'Unknown pipeline emitter "%s". Registered emitters: [%s].',
            $key,
            implode(', ', $available),
        ));
    }
}
