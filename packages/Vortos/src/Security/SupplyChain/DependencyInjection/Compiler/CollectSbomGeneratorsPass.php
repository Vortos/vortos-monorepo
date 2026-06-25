<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectSbomGeneratorsPass extends CollectDriversCompilerPass
{
    public function __construct()
    {
        parent::__construct('vortos.supply_chain.sbom', 'vortos.supply_chain.sbom_locator', 'supply-chain-sbom');
    }
}
