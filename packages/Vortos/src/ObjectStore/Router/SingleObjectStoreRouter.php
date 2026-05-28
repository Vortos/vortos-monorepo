<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Router;

use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Contract\ObjectStoreRouterInterface;

final class SingleObjectStoreRouter implements ObjectStoreRouterInterface
{
    public function __construct(private readonly ObjectStoreInterface $objectStore) {}

    public function default(): ObjectStoreInterface
    {
        return $this->objectStore;
    }

    public function forRoute(string $route): ObjectStoreInterface
    {
        return $this->objectStore;
    }
}
