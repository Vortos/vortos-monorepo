<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Driver\Postgres;

use RuntimeException;
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

    private function guardNonProd(string $dsn): void
    {
        $lower = strtolower($dsn);
        foreach (['production', 'prod-db', 'primary-db'] as $pattern) {
            if (str_contains($lower, $pattern)) {
                throw new RuntimeException('Drill DSN appears to point at a production database — refusing to provision.');
            }
        }
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
