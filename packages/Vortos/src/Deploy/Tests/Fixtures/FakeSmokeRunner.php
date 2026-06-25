<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Gate\SmokeResult;
use Vortos\Deploy\Gate\SmokeRunnerInterface;
use Vortos\Deploy\Gate\SmokeSpec;
use Vortos\Deploy\Target\ActiveColor;

final class FakeSmokeRunner implements SmokeRunnerInterface
{
    public bool $shouldPass = true;

    public function run(ActiveColor $color, ColorEndpoint $endpoint, SmokeSpec $spec): SmokeResult
    {
        return new SmokeResult(passed: $this->shouldPass);
    }
}
