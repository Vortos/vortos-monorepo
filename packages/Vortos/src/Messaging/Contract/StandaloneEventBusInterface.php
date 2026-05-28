<?php

declare(strict_types=1);

namespace Vortos\Messaging\Contract;

/**
 * Reliable async event dispatch outside an application transaction.
 *
 * Implementations write messaging outbox rows inside their own short transaction.
 * Normal domain workflows should use EventBusInterface through CommandBus.
 */
interface StandaloneEventBusInterface extends EventBusInterface
{
}
