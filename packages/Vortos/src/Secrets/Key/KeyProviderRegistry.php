<?php

declare(strict_types=1);

namespace Vortos\Secrets\Key;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class KeyProviderRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('key-provider', $drivers);
    }

    public function provider(string $key): KeyProviderInterface
    {
        /** @var KeyProviderInterface */
        return $this->get($key);
    }
}
