<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectRegistryAuthStrategiesPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.deploy.registry_auth_strategy';
    public const LOCATOR_ID = 'vortos.deploy.registry_auth_strategy_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'registry-auth-strategy');
    }
}
