<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

/**
 * Reliable async object-store operations outside an application transaction.
 *
 * Implementations write to the object-store outbox inside their own short
 * transaction. They are not atomic with domain writes.
 */
interface StandaloneObjectStoreInterface extends ObjectStoreInterface
{
}
