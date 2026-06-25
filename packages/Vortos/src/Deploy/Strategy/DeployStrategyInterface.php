<?php

declare(strict_types=1);

namespace Vortos\Deploy\Strategy;

use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\OpsKit\Driver\Capability\RequiredCapabilities;

interface DeployStrategyInterface
{
    public function key(): DeployStrategy;

    public function requires(): RequiredCapabilities;

    /** @return list<DeployPhase> */
    public function phases(DeployContext $context): array;
}
