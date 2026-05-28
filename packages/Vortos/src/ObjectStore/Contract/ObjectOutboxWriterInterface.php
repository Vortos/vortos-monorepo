<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

interface ObjectOutboxWriterInterface
{
    public function queue(ObjectStoreOperation $operation, ?string $domainEventId = null): void;
}
