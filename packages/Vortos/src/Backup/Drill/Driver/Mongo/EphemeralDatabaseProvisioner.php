<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Driver\Mongo;

use RuntimeException;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\DrillEnvironment;
use Vortos\Backup\Drill\DrillEnvironmentProvisionerInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('mongo')]
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
        if ($engine !== DatabaseEngine::Mongo) {
            throw new RuntimeException('Mongo provisioner cannot provision ' . $engine->value);
        }

        $dbName = 'drill_' . bin2hex(random_bytes(6));

        $parsed = parse_url($this->drillDsn);
        $dsn = sprintf(
            'mongodb://%s%s%s:%d/%s',
            isset($parsed['user']) ? $parsed['user'] . ':' . urlencode($parsed['pass'] ?? '') . '@' : '',
            $parsed['host'] ?? 'localhost',
            '',
            $parsed['port'] ?? 27017,
            $dbName,
        );

        return new DrillEnvironment($dsn, $dbName);
    }

    public function teardown(DrillEnvironment $env): void
    {
        // Mongo drops the database when mongorestore --drop runs, or we can drop explicitly
        // via the mongo shell. For the ephemeral case we rely on the test harness.
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
}
