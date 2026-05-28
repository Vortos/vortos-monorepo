<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

interface ObjectStoreObserverInterface
{
    public function before(ObjectStoreOperation $operation): void;

    public function after(ObjectStoreOperation $operation, mixed $result): void;

    public function failed(ObjectStoreOperation $operation, \Throwable $error): void;
}
