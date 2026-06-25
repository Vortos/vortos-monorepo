<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectContainerRegistriesPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.deploy.container_registry';
    public const LOCATOR_ID = 'vortos.deploy.container_registry_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'container-registry');
    }
}
