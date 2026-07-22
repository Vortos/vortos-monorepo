<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Driver\Postgres;

use RuntimeException;
use Throwable;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\DrillEnvironment;
use Vortos\Backup\Drill\DrillEnvironmentProvisionerInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('postgres')]
final class EphemeralDatabaseProvisioner implements DrillEnvironmentProvisionerInterface
{
    public function __construct(
        private readonly string $drillDsn,
        /**
         * The application's own write-database DSN. When supplied, the guard can compare topology
         * instead of guessing from names — see {@see guardNonProd()}. Optional only so existing
         * call sites keep working; every production wiring should pass it.
         */
        private readonly ?string $primaryDsn = null,
        /**
         * Explicit, deliberate opt-in to drilling on the same server as the primary. Exists so the
         * guard can be strict by default without blocking a small deploy that genuinely has one
         * Postgres and accepts the contention.
         */
        private readonly bool $allowSharedHost = false,
    ) {
        $this->guardNonProd($drillDsn);
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(['ephemeral_db' => true]);
    }

    public function provision(DatabaseEngine $engine): DrillEnvironment
    {
        if ($engine !== DatabaseEngine::Postgres) {
            throw new RuntimeException('Postgres provisioner cannot provision ' . $engine->value);
        }

        $dbName = 'drill_' . bin2hex(random_bytes(6));
        $params = $this->parseDsn($this->drillDsn);

        $pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=postgres', $params['host'], $params['port']),
            $params['user'],
            $params['password'],
        );
        $pdo->exec(sprintf('CREATE DATABASE "%s"', $dbName));

        $dsn = sprintf(
            'pgsql://%s:%s@%s:%d/%s',
            $params['user'],
            urlencode($params['password']),
            $params['host'],
            $params['port'],
            $dbName,
        );

        return new DrillEnvironment($dsn, $dbName);
    }

    public function teardown(DrillEnvironment $env): void
    {
        $params = $this->parseDsn($this->drillDsn);

        $pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=postgres', $params['host'], $params['port']),
            $params['user'],
            $params['password'],
        );

        $pdo->exec(sprintf(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = %s AND pid <> pg_backend_pid()',
            $pdo->quote($env->label),
        ));
        $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s"', $env->label));
    }

    /**
     * Refuse to drill on the production primary.
     *
     * This used to substring-match the DSN against `['production', 'prod-db', 'primary-db']`, which
     * tested a *naming convention* rather than the topology. It gave a false sense of protection and
     * was measured failing in production: the DSN `pgsql://user:pw@write_db:5432/drill` contains none
     * of those tokens, so it passed cleanly while `write_db` was the production primary — the exact
     * situation the guard exists to prevent.
     *
     * Now it compares host and port against the application's real write-database connection, which
     * is the thing that actually determines whether a `CREATE DATABASE` lands on the primary. Names
     * are advisory; the socket is the truth. The old substring heuristic is kept as a secondary hint
     * for deploys that pass no primary DSN, but it is no longer the only line of defence.
     *
     * Prefer {@see ContainerizedDatabaseProvisioner}, which makes the question moot by never touching
     * an existing server at all.
     */
    private function guardNonProd(string $dsn): void
    {
        if ($this->allowSharedHost) {
            return;
        }

        if ($this->primaryDsn !== null && $this->sharesHostWithPrimary($dsn)) {
            throw new RuntimeException(
                'Drill DSN resolves to the same host:port as the application write database — refusing to '
                . 'provision a drill on the production primary. Use ContainerizedDatabaseProvisioner for an '
                . 'isolated server, point the drill at a separate instance, or set the explicit '
                . 'allow-shared-host opt-in if the contention is genuinely acceptable.',
            );
        }

        $lower = strtolower($dsn);
        foreach (['production', 'prod-db', 'primary-db'] as $pattern) {
            if (str_contains($lower, $pattern)) {
                throw new RuntimeException('Drill DSN appears to point at a production database — refusing to provision.');
            }
        }
    }

    private function sharesHostWithPrimary(string $drillDsn): bool
    {
        try {
            $drill = $this->parseDsn($drillDsn);
            $primary = $this->parseDsn((string) $this->primaryDsn);
        } catch (Throwable) {
            // An unparseable DSN is not evidence of safety, but it is not evidence of collision
            // either — let the connection attempt surface the real problem.
            return false;
        }

        return strcasecmp($drill['host'], $primary['host']) === 0 && $drill['port'] === $primary['port'];
    }

    /**
     * @return array{host:string, port:int, user:string, password:string}
     */
    private function parseDsn(string $dsn): array
    {
        $parsed = parse_url($dsn);
        if ($parsed === false) {
            throw new RuntimeException('Invalid drill DSN: ' . $dsn);
        }

        return [
            'host' => $parsed['host'] ?? 'localhost',
            'port' => $parsed['port'] ?? 5432,
            'user' => $parsed['user'] ?? 'postgres',
            'password' => isset($parsed['pass']) ? urldecode($parsed['pass']) : '',
        ];
    }
}
