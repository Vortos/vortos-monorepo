<?php

declare(strict_types=1);

namespace Vortos\Deploy\Target;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class DeployTargetRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('deploy-target', $drivers);
    }

    public function target(string $key): DeployTargetInterface
    {
        /** @var DeployTargetInterface */
        return $this->get($key);
    }
}
