<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

interface ObjectStoreRouterInterface
{
    public function default(): ObjectStoreInterface;

    /**
     * Resolve a named storage route such as tenant, jurisdiction, or region.
     */
    public function forRoute(string $route): ObjectStoreInterface;
}
