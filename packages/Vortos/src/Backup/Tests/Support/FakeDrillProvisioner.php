<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\DrillEnvironment;
use Vortos\Backup\Drill\DrillEnvironmentProvisionerInterface;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/** @internal */
final class FakeDrillProvisioner implements DrillEnvironmentProvisionerInterface
{
    public bool $provisioned = false;
    public bool $tornDown = false;
    public bool $throwOnProvision = false;

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(['ephemeral_db' => true]);
    }

    public function provision(DatabaseEngine $engine): DrillEnvironment
    {
        if ($this->throwOnProvision) {
            throw new \RuntimeException('Provision failed.');
        }
        $this->provisioned = true;

        return new DrillEnvironment('pgsql://test:test@localhost:5432/drill_fake', 'drill_fake');
    }

    public function teardown(DrillEnvironment $env): void
    {
        $this->tornDown = true;
    }
}
