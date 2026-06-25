<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Exception;

final class UnpinnedActionException extends \InvalidArgumentException
{
    public static function forSha(string $sha): self
    {
        return new self(sprintf(
            'Action SHA must be a 40-character lowercase hex string, got "%s". '
            . 'Pin actions by full commit SHA for supply-chain integrity — floating tags (@v4, @main) are not allowed.',
            $sha,
        ));
    }
}
