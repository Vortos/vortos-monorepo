<?php

declare(strict_types=1);

namespace Vortos\Pipeline\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectCiRegistryLoginProvidersPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.pipeline.ci_registry_login';
    public const LOCATOR_ID = 'vortos.pipeline.ci_registry_login_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'ci-registry-login');
    }
}
