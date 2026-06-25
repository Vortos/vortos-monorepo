<?php

declare(strict_types=1);

namespace Vortos\Observability\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectMarkerEmittersPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.observability.marker_emitter';
    public const LOCATOR_ID = 'vortos.observability.marker_emitter_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'observability_marker_emitter');
    }
}
