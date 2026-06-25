<?php

declare(strict_types=1);

namespace Vortos\Deploy\Gate;

use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Target\ActiveColor;

interface ReadinessGateInterface
{
    public function awaitReady(ActiveColor $color, ColorEndpoint $endpoint, GateBudget $budget): GateResult;
}
