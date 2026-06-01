<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Outbox;

interface ObjectStoreOutboxRelayInterface
{
    public function relay(): int;
}
