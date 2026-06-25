<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Port;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class SbomGeneratorRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('supply-chain-sbom', $drivers);
    }

    public function generator(string $key): SbomGeneratorInterface
    {
        /** @var SbomGeneratorInterface */
        return $this->get($key);
    }
}
