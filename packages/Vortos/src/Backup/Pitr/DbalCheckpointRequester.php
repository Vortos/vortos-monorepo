<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Doctrine\DBAL\Connection;

final class DbalCheckpointRequester implements CheckpointRequesterInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function checkpoint(): void
    {
        $this->connection->executeStatement('CHECKPOINT');
    }
}
