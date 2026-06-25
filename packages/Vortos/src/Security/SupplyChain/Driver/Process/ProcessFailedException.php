<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Process;

use Vortos\Security\SupplyChain\Model\SupplyChainException;

final class ProcessFailedException extends SupplyChainException
{
    public static function fromOutput(string $binary, ProcessOutput $output): self
    {
        return new self(sprintf(
            '%s exited with code %d: %s',
            $binary,
            $output->exitCode,
            trim($output->stderr) !== '' ? $output->stderr : $output->stdout,
        ));
    }

    public static function timeout(string $binary, int $seconds): self
    {
        return new self(sprintf(
            '%s timed out after %d seconds.',
            $binary,
            $seconds,
        ));
    }
}
