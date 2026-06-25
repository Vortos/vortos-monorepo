<?php

declare(strict_types=1);

namespace Vortos\Secrets\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectKeyProvidersPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.secrets.key_provider';
    public const LOCATOR_ID = 'vortos.secrets.key_provider_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'key-provider');
    }
}
