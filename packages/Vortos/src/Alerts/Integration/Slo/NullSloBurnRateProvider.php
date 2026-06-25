<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Slo;

use Vortos\Alerts\Rule\Sample\BurnRateSample;
use Vortos\Observability\Slo\Slo;

/** Always reports zero burn — never fires. Safe default until an app wires a real metrics-backed provider. */
final class NullSloBurnRateProvider implements SloBurnRateProviderInterface
{
    public function sample(Slo $slo): BurnRateSample
    {
        return new BurnRateSample(0.0, 0.0);
    }
}
