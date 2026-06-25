<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry\Auth;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class RegistryAuthStrategyRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('registry-auth-strategy', $drivers);
    }

    public function strategy(string $key): RegistryAuthStrategyInterface
    {
        /** @var RegistryAuthStrategyInterface */
        return $this->get($key);
    }
}
