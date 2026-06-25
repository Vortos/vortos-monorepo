<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectCredentialProvidersPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.deploy.credential';
    public const LOCATOR_ID = 'vortos.deploy.credential_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'deploy-credential');
    }
}
