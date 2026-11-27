<?php

declare(strict_types=1);

namespace Vortos\PersistenceDbal\Connection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A DBAL connection that proves itself alive before it is reused after an idle gap, and is retired
 * at a unit-of-work boundary once it has lived long enough.
 *
 * WHY. A long-running process (a FrankenPHP worker, a Kafka consumer, the scheduler) holds its
 * connection across requests. When the server restarts underneath it (a crash recovery, a container
 * recreate, a failover) the socket is dead but DBAL still holds it, and the first query on it fails
 * with "connection lost". On 2026-11-22 the database crash-restarted at 00:20 UTC and the app served
 * ConnectionLost errors until 06:22: UnitOfWork::ensureConnection() pinged only on the command path,
 * so reads, the tenant middleware and side connections each failed once per dead socket.
 *
 * HOW. Every DBAL operation goes through connect(). Here, before handing back a held socket that has
 * been idle for at least `vortos_idle_ping_seconds` (default 30), it runs `SELECT 1` on it; if that
 * fails the socket is dropped and DBAL opens a new one with the same params (so TLS settings in the
 * DSN apply again). A socket in use within the last few seconds is never pinged, so a busy request
 * pays nothing, and a request after a quiet spell pays one round trip.
 *
 * NEVER INSIDE A TRANSACTION. A ping that fails inside an open transaction means the transaction is
 * already gone; reconnecting would silently run the rest of it in autocommit. So a held socket with
 * an open transaction is returned as-is and the failure surfaces as the error it is.
 *
 * RETIREMENT. reset() runs after each request and each consumed message (ServicesResetter). There,
 * outside a transaction, a connection older than `vortos_max_lifetime_seconds` (default 3600; 0 turns
 * it off) is closed, so the next unit of work starts on a fresh one. It happens only at that boundary:
 * the request-scoped session settings (the tenant GUC) are bound at the start of each unit, so closing
 * mid-request would drop them.
 */
class ResilientConnection extends Connection implements ResetInterface
{
    public const PARAM_IDLE_PING_SECONDS    = 'vortos_idle_ping_seconds';
    public const PARAM_MAX_LIFETIME_SECONDS = 'vortos_max_lifetime_seconds';

    private const DEFAULT_IDLE_PING_SECONDS    = 30.0;
    private const DEFAULT_MAX_LIFETIME_SECONDS = 3600.0;

    private ?float $connectedAt = null;
    private ?float $lastUsedAt  = null;

    /** @var (\Closure(): float)|null tests only */
    private ?\Closure $clock = null;

    protected function connect(): DriverConnection
    {
        $now = $this->now();

        if ($this->_conn !== null && !$this->isTransactionActive()
            && $this->lastUsedAt !== null && ($now - $this->lastUsedAt) >= $this->idlePingSeconds()) {
            try {
                $this->_conn->query('SELECT 1');
            } catch (\Throwable) {
                $this->close();
            }
        }

        $opening    = $this->_conn === null;
        $connection = parent::connect();

        if ($opening) {
            $this->connectedAt = $this->now();
        }
        $this->lastUsedAt = $this->now();

        return $connection;
    }

    public function close(): void
    {
        parent::close();
        $this->connectedAt = null;
        $this->lastUsedAt  = null;
    }

    public function reset(): void
    {
        $maxLifetime = $this->maxLifetimeSeconds();

        if ($this->_conn === null || $this->isTransactionActive() || $maxLifetime <= 0.0 || $this->connectedAt === null) {
            return;
        }

        if (($this->now() - $this->connectedAt) >= $maxLifetime) {
            $this->close();
        }
    }

    /** @internal tests only */
    public function withClock(\Closure $clock): static
    {
        $this->clock = $clock;

        return $this;
    }

    private function idlePingSeconds(): float
    {
        return $this->setting(self::PARAM_IDLE_PING_SECONDS, self::DEFAULT_IDLE_PING_SECONDS);
    }

    private function maxLifetimeSeconds(): float
    {
        return $this->setting(self::PARAM_MAX_LIFETIME_SECONDS, self::DEFAULT_MAX_LIFETIME_SECONDS);
    }

    /** These two keys are ours, so they are not in DBAL's params shape; read them as plain array keys. */
    private function setting(string $key, float $default): float
    {
        /** @var array<string, mixed> $params */
        $params = $this->getParams();
        $value  = $params[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
