<?php

declare(strict_types=1);

namespace Vortos\Iac\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectIacEnginesPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.iac.engine';
    public const LOCATOR_ID = 'vortos.iac.engine_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'iac-engine');
    }
}
