<?php

declare(strict_types=1);

namespace Vortos\Messaging\Bus;

use Doctrine\DBAL\Connection;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Messaging\Contract\StandaloneEventBusInterface;

final class StandaloneEventBus implements StandaloneEventBusInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EventBusInterface $transactionalEventBus,
    ) {}

    public function dispatch(EventEnvelope $envelope): void
    {
        $this->write(function () use ($envelope): void {
            $this->transactionalEventBus->dispatch($envelope);
        });
    }

    public function dispatchBatch(EventEnvelope ...$envelopes): void
    {
        $this->write(function () use ($envelopes): void {
            $this->transactionalEventBus->dispatchBatch(...$envelopes);
        });
    }

    private function write(callable $operation): void
    {
        if ($this->connection->isTransactionActive()) {
            $operation();
            return;
        }

        $this->connection->transactional($operation);
    }
}
