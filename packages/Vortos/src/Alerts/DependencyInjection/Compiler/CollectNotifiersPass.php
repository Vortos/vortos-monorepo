<?php

declare(strict_types=1);

namespace Vortos\Alerts\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectNotifiersPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.alerts.notifier';
    public const LOCATOR_ID = 'vortos.alerts.notifier_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'alerts_notifier');
    }
}
