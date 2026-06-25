<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

final class CutoverFailedException extends DeployException
{
    public static function verifyMismatch(string $expected, string $actual): self
    {
        return new self(sprintf(
            'Cutover verify failed: expected upstream %s, got %s — reverting.',
            $expected,
            $actual,
        ));
    }

    public static function reloadFailed(string $reason): self
    {
        return new self(sprintf('Edge reload failed: %s — reverting.', $reason));
    }

    public static function adminUnreachable(string $reason): self
    {
        return new self(sprintf('Edge admin API unreachable: %s — fail-closed, reverting.', $reason));
    }

    public static function metricsUnreachable(string $reason): self
    {
        return new self(sprintf('Edge metrics endpoint unreachable: %s — fail-closed, drain state unknown.', $reason));
    }

    public static function metricsUnavailable(): self
    {
        return new self('Edge metrics endpoint returned no in-flight request metric — fail-closed, drain state unknown.');
    }
}
