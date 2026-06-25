<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectKevCatalogProvidersPass extends CollectDriversCompilerPass
{
    public function __construct()
    {
        parent::__construct('vortos.supply_chain.kev', 'vortos.supply_chain.kev_locator', 'supply-chain-kev');
    }
}
