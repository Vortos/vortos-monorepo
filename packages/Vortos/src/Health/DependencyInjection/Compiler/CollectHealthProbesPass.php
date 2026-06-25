<?php

declare(strict_types=1);

namespace Vortos\Health\DependencyInjection\Compiler;

use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectHealthProbesPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.health.probe';
    public const LOCATOR_ID = 'vortos.health.probe_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'health');
    }
}
