<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectDeployStateStoresPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.deploy.state_store';
    public const LOCATOR_ID = 'vortos.deploy.state_store_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'deploy-state-store');
    }
}
