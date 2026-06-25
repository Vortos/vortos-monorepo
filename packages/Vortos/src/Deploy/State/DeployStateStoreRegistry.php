<?php

declare(strict_types=1);

namespace Vortos\Deploy\State;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class DeployStateStoreRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('deploy-state-store', $drivers);
    }

    public function store(string $key): DeployStateStoreInterface
    {
        /** @var DeployStateStoreInterface */
        return $this->get($key);
    }
}
