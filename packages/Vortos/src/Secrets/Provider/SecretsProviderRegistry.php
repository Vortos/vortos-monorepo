<?php

declare(strict_types=1);

namespace Vortos\Secrets\Provider;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class SecretsProviderRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('secrets', $drivers);
    }

    public function provider(string $key): SecretsProviderInterface
    {
        /** @var SecretsProviderInterface */
        return $this->get($key);
    }
}
