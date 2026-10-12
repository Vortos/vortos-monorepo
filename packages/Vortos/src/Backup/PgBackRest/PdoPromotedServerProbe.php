<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use PDO;
use PDOException;

final class PdoPromotedServerProbe implements PromotedServerProbeInterface
{
    public function promotedReplayLsn(array $connection): ?string
    {
        try {
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=3', $connection['host'], $connection['port'], $connection['dbname']),
                $connection['user'],
                $connection['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            // pg_last_wal_replay_lsn() stays at the last record applied during recovery once recovery has
            // ended, which is exactly the point the drill reached.
            $statement = $pdo->query('select pg_is_in_recovery() as recovering, pg_last_wal_replay_lsn()::text as lsn');
            $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return null;
        }

        if (!\is_array($row) || $row['recovering'] !== false || !\is_string($row['lsn'] ?? null)) {
            return null;
        }

        return $row['lsn'];
    }
}
