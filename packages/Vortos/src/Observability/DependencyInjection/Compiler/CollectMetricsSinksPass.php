<?php

declare(strict_types=1);

namespace Vortos\Observability\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectMetricsSinksPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.observability.metrics_sink';
    public const LOCATOR_ID = 'vortos.observability.metrics_sink_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'observability_metrics_sink');
    }
}
