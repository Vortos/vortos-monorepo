<?php

declare(strict_types=1);

namespace Vortos\Deploy\Gate;

use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Target\ActiveColor;

interface SmokeRunnerInterface
{
    public function run(ActiveColor $color, ColorEndpoint $endpoint, SmokeSpec $spec): SmokeResult;
}
