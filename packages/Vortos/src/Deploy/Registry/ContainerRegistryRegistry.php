<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class ContainerRegistryRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('container-registry', $drivers);
    }

    public function registry(string $key): ContainerRegistryInterface
    {
        /** @var ContainerRegistryInterface */
        return $this->get($key);
    }
}
