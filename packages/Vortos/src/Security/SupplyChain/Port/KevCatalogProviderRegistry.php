<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Port;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class KevCatalogProviderRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('supply-chain-kev', $drivers);
    }

    public function provider(string $key): KevCatalogProviderInterface
    {
        /** @var KevCatalogProviderInterface */
        return $this->get($key);
    }
}
