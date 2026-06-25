<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Health;

use Doctrine\DBAL\Connection;

/** Default (prod) store, table `vortos_alerts_uptime_streaks` — survives across CLI tick invocations. */
final class DbalUptimeUnknownStreakStore implements UptimeUnknownStreakStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function increment(string $monitorId): int
    {
        return $this->connection->transactional(function (Connection $conn) use ($monitorId): int {
            $current = $conn->fetchOne(
                sprintf('SELECT streak FROM %s WHERE monitor_id = :monitor_id', $this->table),
                ['monitor_id' => $monitorId],
            );

            if ($current === false) {
                $conn->insert($this->table, ['monitor_id' => $monitorId, 'streak' => 1]);

                return 1;
            }

            $next = ((int) $current) + 1;
            $conn->update($this->table, ['streak' => $next], ['monitor_id' => $monitorId]);

            return $next;
        });
    }

    public function reset(string $monitorId): void
    {
        $this->connection->delete($this->table, ['monitor_id' => $monitorId]);
    }
}
