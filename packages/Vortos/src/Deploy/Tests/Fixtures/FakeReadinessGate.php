<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Gate\GateBudget;
use Vortos\Deploy\Gate\GateResult;
use Vortos\Deploy\Gate\ReadinessGateInterface;
use Vortos\Deploy\Target\ActiveColor;

final class FakeReadinessGate implements ReadinessGateInterface
{
    public bool $shouldPass = true;
    public int $attempts = 1;

    public function awaitReady(ActiveColor $color, ColorEndpoint $endpoint, GateBudget $budget): GateResult
    {
        return new GateResult(
            passed: $this->shouldPass,
            attempts: $this->attempts,
            elapsed: 0.1,
            lastStatusCode: $this->shouldPass ? 200 : 503,
        );
    }
}
