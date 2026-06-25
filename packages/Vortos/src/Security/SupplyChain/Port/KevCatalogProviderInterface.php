<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Port;

use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\Security\SupplyChain\Model\SupplyChainException;
use Vortos\Security\SupplyChain\Model\Vulnerability\KevCatalog;

interface KevCatalogProviderInterface extends DriverInterface
{
    /** @throws SupplyChainException when catalog is unavailable (fail-closed) */
    public function catalog(): KevCatalog;
}
