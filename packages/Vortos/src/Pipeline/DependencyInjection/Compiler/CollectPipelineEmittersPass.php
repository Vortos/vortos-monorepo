<?php

declare(strict_types=1);

namespace Vortos\Pipeline\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectPipelineEmittersPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.pipeline.emitter';
    public const LOCATOR_ID = 'vortos.pipeline.emitter_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'pipeline_emitter');
    }
}
