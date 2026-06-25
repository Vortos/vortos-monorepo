<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

use Vortos\OpsKit\Driver\DriverInterface;

/**
 * The canary analysis port — maps an analysis request to a verdict.
 *
 * Fail-closed contract: any error (query timeout, empty series, unreachable backend)
 * MUST return Inconclusive, never Progress. The only way to return Progress is
 * positive proof of parity over a sufficient sample.
 */
interface CanaryAnalyzerInterface extends DriverInterface
{
    public function analyze(CanaryAnalysisRequest $request): CanaryVerdict;
}
