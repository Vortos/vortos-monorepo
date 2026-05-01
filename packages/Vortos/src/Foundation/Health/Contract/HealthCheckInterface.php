<?php

declare(strict_types=1);

namespace Vortos\Foundation\Health\Contract;

use Vortos\Foundation\Health\HealthResult;

interface HealthCheckInterface
{
    public function name(): string;

    public function check(): HealthResult;
}
