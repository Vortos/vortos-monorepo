<?php

declare(strict_types=1);

namespace Vortos\Persistence\Transaction;

use Doctrine\DBAL\Connection;

final class ActiveTransactionGuard
{
    public function __construct(private readonly Connection $connection) {}

    public function assertActive(string $operation, string $standaloneInterface, string $immediateInterface): void
    {
        if ($this->connection->isTransactionActive()) {
            return;
        }

        throw new TransactionRequiredException(sprintf(
            '%s requires an active database transaction. Use CommandBus for business workflows, %s for standalone async outbox work, or %s for direct provider calls.',
            $operation,
            $standaloneInterface,
            $immediateInterface,
        ));
    }
}
