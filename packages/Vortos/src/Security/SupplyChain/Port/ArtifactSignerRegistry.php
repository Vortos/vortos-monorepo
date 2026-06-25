<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Port;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class ArtifactSignerRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('supply-chain-signer', $drivers);
    }

    public function signer(string $key): ArtifactSignerInterface
    {
        /** @var ArtifactSignerInterface */
        return $this->get($key);
    }
}
