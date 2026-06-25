<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Registry;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class CiRegistryLoginProviderRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('ci-registry-login', $drivers);
    }

    public function provider(string $key): CiRegistryLoginProviderInterface
    {
        /** @var CiRegistryLoginProviderInterface */
        return $this->get($key);
    }
}
