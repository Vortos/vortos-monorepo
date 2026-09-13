<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Reads `wal_segment_size` from the server. `pg_settings.setting` is in bytes for this parameter,
 * unlike `SHOW`, which formats it for humans ("2MB").
 */
final class PgSettingsWalFileSizeResolver implements WalFileSizeResolverInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function segmentBytes(): int
    {
        $value = $this->connection->fetchOne("SELECT setting FROM pg_settings WHERE name = 'wal_segment_size'");

        if ($value === false || !is_numeric($value)) {
            throw new RuntimeException('wal_segment_size is not readable from pg_settings');
        }

        return (int) $value;
    }
}
