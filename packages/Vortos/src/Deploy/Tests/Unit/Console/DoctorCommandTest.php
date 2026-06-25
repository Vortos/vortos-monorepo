<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Deploy\Console\DoctorCommand;
use Vortos\Deploy\Plan\PhaseGate;
use Vortos\Deploy\Preflight\Check\CapabilityDescriptorCheck;
use Vortos\Deploy\Preflight\Check\CredentialCheck;
use Vortos\Deploy\Preflight\Check\DriverSetCheck;
use Vortos\Deploy\Preflight\Check\SchemaCompatibilityCheck;
use Vortos\Deploy\Preflight\Check\TargetArchCheck;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Tests\Fixtures\FakeManifestReadModel;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Release\Schema\SchemaFingerprint;

final class DoctorCommandTest extends TestCase
{
    use PreflightTestFactory;

    public function test_clear_exits_zero(): void
    {
        $tester = new CommandTester($this->command(credential: 'fake-credential'));

        $exit = $tester->execute(['--env' => 'production']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('CLEAR', $tester->getDisplay());
    }

    public function test_gap_exits_one(): void
    {
        $tester = new CommandTester($this->command(credential: 'ghost'));

        $exit = $tester->execute(['--env' => 'production']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('REFUSED', $tester->getDisplay());
    }

    public function test_json_emits_parseable_report(): void
    {
        $tester = new CommandTester($this->command(credential: 'fake-credential'));

        $tester->execute(['--env' => 'production', '--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0', $decoded['schema_version']);
        $this->assertTrue($decoded['clear']);
        $this->assertArrayHasKey('findings', $decoded);
    }

    public function test_strict_flag_runs_and_stays_clear(): void
    {
        $tester = new CommandTester($this->command(credential: 'fake-credential'));

        $exit = $tester->execute(['--env' => 'production', '--strict' => true]);

        $this->assertSame(0, $exit);
    }

    private function command(string $credential): DoctorCommand
    {
        $targets = $this->targetRegistry();
        $manifests = new FakeManifestReadModel(
            latest: $this->manifest(['m001']),
            applied: new SchemaFingerprint(['m001']),
            knownIds: ['m001'],
        );

        $factory = new PreflightContextFactory(
            $this->resolver(credential: $credential),
            $manifests,
            $this->stateBuilder(['m001']),
            $targets,
        );

        $doctor = new DeployDoctor([
            new DriverSetCheck($targets, $this->registryRegistry(), $this->credentialRegistry(), $this->strategyRegistry()),
            new CapabilityDescriptorCheck($targets, $this->strategyRegistry()),
            new CredentialCheck($this->credentialRegistry()),
            new TargetArchCheck($targets),
            new SchemaCompatibilityCheck(new PhaseGate(), $manifests),
        ]);

        return new DoctorCommand($factory, $doctor);
    }
}
