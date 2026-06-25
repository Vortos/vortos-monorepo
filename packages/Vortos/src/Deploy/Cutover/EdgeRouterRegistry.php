<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class EdgeRouterRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('edge-router', $drivers);
    }

    public function router(string $key): EdgeRouterInterface
    {
        /** @var EdgeRouterInterface */
        return $this->get($key);
    }
}
