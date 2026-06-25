<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\DependencyInjection\Compiler;

use Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass;

final class CollectArtifactSignersPass extends CollectDriversCompilerPass
{
    public function __construct()
    {
        parent::__construct('vortos.supply_chain.signer', 'vortos.supply_chain.signer_locator', 'supply-chain-signer');
    }
}
