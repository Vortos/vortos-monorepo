<?php

declare(strict_types=1);

namespace Vortos\Analytics\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectAnalyticsDriversPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.analytics.driver';
    public const LOCATOR_ID = 'vortos.analytics.driver_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'analytics');
    }
}
