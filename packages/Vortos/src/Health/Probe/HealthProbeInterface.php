<?php

declare(strict_types=1);

namespace Vortos\Health\Probe;

use Vortos\OpsKit\Driver\DriverInterface;

interface HealthProbeInterface extends DriverInterface
{
    public function name(): string;

    public function kind(): ProbeKind;

    public function check(): ProbeResult;
}
