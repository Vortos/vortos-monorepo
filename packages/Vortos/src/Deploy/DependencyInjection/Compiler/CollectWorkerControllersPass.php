<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectWorkerControllersPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.deploy.worker_controller';
    public const LOCATOR_ID = 'vortos.deploy.worker_controller_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'worker-controller');
    }
}
