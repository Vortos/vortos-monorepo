<?php

declare(strict_types=1);

namespace Vortos\Secrets\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectSecretsProvidersPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.secrets.provider';
    public const LOCATOR_ID = 'vortos.secrets.provider_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'secrets');
    }
}
