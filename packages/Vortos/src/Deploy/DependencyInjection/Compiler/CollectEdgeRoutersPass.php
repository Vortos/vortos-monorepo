<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectEdgeRoutersPass extends CollectDriversCompilerPass
{
    public const TAG = 'vortos.deploy.edge_router';
    public const LOCATOR_ID = 'vortos.deploy.edge_router_locator';

    public function __construct()
    {
        parent::__construct(self::TAG, self::LOCATOR_ID, 'edge-router');
    }
}
