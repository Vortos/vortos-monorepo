<?php

declare(strict_types=1);

namespace Vortos\Health\Monitor;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/** Default (prod) store, table `vortos_uptime_sync` — one row per (env, journey_key). */
final class DbalSyncRecordStore implements SyncRecordStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function lastHash(string $env, string $journeyKey): ?string
    {
        $hash = $this->connection->fetchOne(
            sprintf('SELECT payload_hash FROM %s WHERE env = :env AND journey_key = :journey_key', $this->table),
            ['env' => $env, 'journey_key' => $journeyKey],
        );

        return $hash === false ? null : (string) $hash;
    }

    public function record(string $env, string $journeyKey, string $hash, string $monitorId): void
    {
        $this->connection->transactional(function (Connection $conn) use ($env, $journeyKey, $hash, $monitorId): void {
            $existing = $conn->fetchOne(
                sprintf('SELECT env FROM %s WHERE env = :env AND journey_key = :journey_key', $this->table),
                ['env' => $env, 'journey_key' => $journeyKey],
            );

            $row = [
                'env' => $env,
                'journey_key' => $journeyKey,
                'payload_hash' => $hash,
                'monitor_id' => $monitorId,
                'applied_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            ];

            if ($existing === false) {
                $conn->insert($this->table, $row);

                return;
            }

            $conn->update($this->table, $row, ['env' => $env, 'journey_key' => $journeyKey]);
        });
    }
}
