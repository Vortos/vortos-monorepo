<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\OpsKit\Driver\Capability\CapabilityMismatchException;

final class StrategyCapabilityException extends DeployException
{
    public static function fromMismatch(
        DeployStrategy $strategy,
        string $targetKey,
        CapabilityMismatchException $inner,
    ): self {
        return new self(
            sprintf(
                'Strategy "%s" is incompatible with target "%s": %s',
                $strategy->value,
                $targetKey,
                $inner->getMessage(),
            ),
            previous: $inner,
        );
    }
}
