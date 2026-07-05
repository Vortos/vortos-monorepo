<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Definition\WorkerTopology;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\Check\RootlessWorkerCheck;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * GAP-B: the doctor fails closed when the worker's supervisord config is rootful in the single-image
 * (RideColor) model, so the "Can't drop privilege as nonroot user" crash-loop is caught before the box.
 */
final class RootlessWorkerCheckTest extends TestCase
{
    private const ROOTFUL = "[supervisord]\nuser=root\npidfile=/var/run/supervisord.pid\n";
    private const ROOTLESS = "[supervisord]\nnodaemon=true\npidfile=/tmp/supervisord.pid\nlogfile=/dev/stdout\n";

    public function test_rootful_config_fails_closed_for_non_root_image(): void
    {
        $finding = $this->check(config: self::ROOTFUL, uid: 1000)
            ->check($this->context(WorkerTopology::RideColor, RuntimeServiceSpec::DEFAULT_WORKER_COMMAND));

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('user=root', $finding->detail);
        self::assertStringContainsString('supervisord.rootless.conf', $finding->remediation);
    }

    public function test_root_owned_pidfile_without_user_directive_also_fails(): void
    {
        $config = "[supervisord]\nnodaemon=true\npidfile=/var/run/supervisord.pid\n";

        $finding = $this->check(config: $config, uid: 1000)
            ->check($this->context(WorkerTopology::RideColor, RuntimeServiceSpec::DEFAULT_WORKER_COMMAND));

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('/var/run', $finding->detail);
    }

    public function test_rootless_config_passes(): void
    {
        $finding = $this->check(config: self::ROOTLESS, uid: 1000)
            ->check($this->context(WorkerTopology::RideColor, RuntimeServiceSpec::DEFAULT_WORKER_COMMAND));

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_external_supervisor_topology_is_a_pass(): void
    {
        $finding = $this->check(config: self::ROOTFUL, uid: 1000)
            ->check($this->context(WorkerTopology::ExternalSupervisor, RuntimeServiceSpec::DEFAULT_WORKER_COMMAND));

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_root_runtime_is_a_pass(): void
    {
        $finding = $this->check(config: self::ROOTFUL, uid: 0)
            ->check($this->context(WorkerTopology::RideColor, RuntimeServiceSpec::DEFAULT_WORKER_COMMAND));

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_non_supervisord_worker_command_is_a_pass(): void
    {
        $finding = $this->check(config: self::ROOTFUL, uid: 1000)
            ->check($this->context(WorkerTopology::RideColor, ['php', 'bin/console', 'messenger:consume', 'async']));

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_absent_config_is_skipped_not_failed(): void
    {
        $check = new RootlessWorkerCheck(
            uidProvider: static fn (): int => 1000,
            configReader: static fn (string $p): ?string => null,
        );

        $finding = $check->check($this->context(WorkerTopology::RideColor, RuntimeServiceSpec::DEFAULT_WORKER_COMMAND));

        self::assertSame(PreflightStatus::Skip, $finding->status);
        self::assertStringContainsString('supervisord.rootless.conf', $finding->summary);
    }

    public function test_custom_config_path_from_worker_command_is_read(): void
    {
        $seen = null;
        $check = new RootlessWorkerCheck(
            uidProvider: static fn (): int => 1000,
            configReader: static function (string $p) use (&$seen): ?string {
                $seen = $p;

                return self::ROOTLESS;
            },
        );

        $check->check($this->context(
            WorkerTopology::RideColor,
            ['/usr/bin/supervisord', '-c', '/custom/supervisord.conf'],
        ));

        self::assertSame('/custom/supervisord.conf', $seen);
    }

    public function test_canonical_scaffold_asset_is_rootless(): void
    {
        $path = \dirname(__DIR__, 3) . '/Resources/worker/supervisord.rootless.conf';
        self::assertFileExists($path);

        $contents = (string) file_get_contents($path);

        // Scan active directives only — the header comment legitimately names the root-owned dirs it
        // exists to avoid.
        $active = implode("\n", array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => !str_starts_with(ltrim($line), ';'),
        ));

        self::assertDoesNotMatchRegularExpression('/^\s*user\s*=\s*root\b/mi', $active, 'scaffold must not run as root');
        self::assertStringNotContainsString('/var/run', $active);
        self::assertStringNotContainsString('/var/log/supervisor', $active);
    }

    private function check(string $config, int $uid): RootlessWorkerCheck
    {
        return new RootlessWorkerCheck(
            uidProvider: static fn (): int => $uid,
            configReader: static fn (string $p): ?string => $config,
        );
    }

    /** @param list<string> $workerCommand */
    private function context(WorkerTopology $topology, array $workerCommand): PreflightContext
    {
        $definition = new DeploymentDefinition(
            host: 'ssh-compose',
            registry: 'dockerhub',
            ci: 'github',
            secrets: 'age',
            monitoring: 'grafana',
            notifiers: [],
            credential: 'ssh-key',
            strategy: DeployStrategy::BlueGreen,
            arch: Arch::Arm64,
            autoRollback: true,
            definitionHash: 'test-hash',
            runtimeService: new RuntimeServiceSpec(workerCommand: $workerCommand),
            workerTopology: $topology,
        );

        $manifest = new BuildManifest(
            buildId: 'build-1',
            gitSha: str_repeat('a', 40),
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: 'sha256:' . str_repeat('ab', 32),
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );

        $state = new CurrentDeployState(
            activeColor: ActiveColor::Blue,
            currentDigest: 'sha256:' . str_repeat('ab', 32),
            appliedFingerprint: SchemaFingerprint::empty(),
        );

        return new PreflightContext($definition, $manifest, $state, new EnvironmentName('production'));
    }
}
