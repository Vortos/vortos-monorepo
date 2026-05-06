<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

final class MigrationLock
{
    private const LOCK_ID = 918273645;

    private bool $acquired = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function acquire(int $timeoutSeconds = 60): bool
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return true;
        }

        $deadline = time() + max(0, $timeoutSeconds);

        do {
            $acquired = (bool) $this->connection->fetchOne('SELECT pg_try_advisory_lock(?)', [self::LOCK_ID]);

            if ($acquired) {
                $this->acquired = true;
                return true;
            }

            usleep(250_000);
        } while (time() < $deadline);

        return false;
    }

    public function release(): void
    {
        if (!$this->acquired || !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        $this->connection->fetchOne('SELECT pg_advisory_unlock(?)', [self::LOCK_ID]);
        $this->acquired = false;
    }
}
