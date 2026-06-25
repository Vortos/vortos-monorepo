<?php

declare(strict_types=1);

namespace Vortos\Iac\Exception;

final class DestructiveChangeRefusedException extends IacException
{
    /** @param list<string> $addresses */
    public static function overLimit(int $count, int $max, array $addresses): self
    {
        return new self(sprintf(
            "Plan contains %d destructive change(s) (limit: %d). Affected resources:\n  - %s\n"
            . 'Use --allow-destructive to override.',
            $count,
            $max,
            implode("\n  - ", $addresses),
        ));
    }
}
