<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

final class CutoverRevertedException extends DeployException
{
    public static function afterVerifyFailure(string $previousColor, string $reason): self
    {
        return new self(sprintf(
            'Cutover reverted to %s after failure: %s',
            $previousColor,
            $reason,
        ));
    }

    public static function noPreviousColor(string $reason): self
    {
        return new self(sprintf(
            'Cutover failed with no previous color to revert to: %s',
            $reason,
        ));
    }
}
