<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectCanaryAnalyzersPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.deploy.canary_analyzer';
    public const LOCATOR_ID = 'vortos.deploy.canary_analyzer_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'canary-analyzer');
    }
}
