<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

final class DeployAbortedException extends DeployException
{
    public static function healthGateFailed(string $color, int $attempts): self
    {
        return new self(sprintf(
            'Health gate failed for color %s after %d attempts — aborting, previous color unchanged.',
            $color,
            $attempts,
        ));
    }

    public static function smokeFailed(string $color, string $reason = ''): self
    {
        return new self(sprintf(
            'Smoke test failed for color %s%s — aborting, previous color unchanged.',
            $color,
            $reason !== '' ? ": {$reason}" : '',
        ));
    }

    public static function digestNotPinned(string $ref): self
    {
        return new self(sprintf(
            'Image reference is not digest-pinned: %s — mutable tags are not allowed at runtime.',
            $ref,
        ));
    }

    public static function unknownStepAction(string $action): self
    {
        return new self(sprintf(
            'Unknown step action "%s" — fail-closed, aborting deploy.',
            $action,
        ));
    }
}
