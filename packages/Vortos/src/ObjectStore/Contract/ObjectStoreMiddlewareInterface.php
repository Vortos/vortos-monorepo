<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

interface ObjectStoreMiddlewareInterface
{
    public function process(ObjectStoreOperation $operation, callable $next): mixed;
}
