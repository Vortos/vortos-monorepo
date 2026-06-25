<?php

declare(strict_types=1);

namespace Vortos\Observability\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectErrorSinksPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.observability.error_sink';
    public const LOCATOR_ID = 'vortos.observability.error_sink_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'observability_error_sink');
    }
}
