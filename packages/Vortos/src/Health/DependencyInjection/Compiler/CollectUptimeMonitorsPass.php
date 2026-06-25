<?php

declare(strict_types=1);

namespace Vortos\Health\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectUptimeMonitorsPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.health.uptime_monitor';
    public const LOCATOR_ID = 'vortos.health.uptime_monitor_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'uptime_monitor');
    }
}
