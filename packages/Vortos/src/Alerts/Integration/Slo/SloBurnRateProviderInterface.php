<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Slo;

use Vortos\Alerts\Rule\Sample\BurnRateSample;
use Vortos\Observability\Slo\Slo;

/**
 * The seam between a declared {@see Slo} and whatever metrics backend actually
 * computes its observed burn rate — kept out of `vortos-alerts` core (no backend
 * coupling, per the Block 16 hand-off note). An app wires a concrete provider reading
 * its own metrics store; {@see NullSloBurnRateProvider} is the safe (never-fires) default.
 */
interface SloBurnRateProviderInterface
{
    public function sample(Slo $slo): BurnRateSample;
}
