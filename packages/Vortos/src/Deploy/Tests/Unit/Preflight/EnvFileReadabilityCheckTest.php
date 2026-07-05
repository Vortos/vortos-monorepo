<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\Check\EnvFileReadabilityCheck;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * GAP-A: the doctor fails closed when a runtime env file is present but unreadable by the deploy
 * one-shot uid — the exact wrong-posture (0600) case that otherwise only surfaces at cutover when the
 * nested `docker compose up` parses env_file: and dies "permission denied".
 */
final class EnvFileReadabilityCheckTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
    }

    public function test_present_but_unreadable_env_file_fails_closed(): void
    {
        $path = $this->tempEnvFile();

        $check = new EnvFileReadabilityCheck(
            // Deterministic regardless of runner uid: the file exists, but the one-shot uid can't read it.
            isReadable: static fn (string $p): bool => $p !== $path,
        );

        $finding = $check->check($this->context([$path]));

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertTrue($finding->isFailure());
        self::assertStringContainsString($path, $finding->detail);
        self::assertStringContainsString('chmod 640', $finding->remediation);
    }

    public function test_readable_env_file_passes(): void
    {
        $path = $this->tempEnvFile();

        $finding = (new EnvFileReadabilityCheck())->check($this->context([$path]));

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_absent_env_file_is_skipped_not_failed(): void
    {
        // Off-target contexts (doctor run away from the box) must not false-fail on a not-yet-present file.
        $finding = (new EnvFileReadabilityCheck())->check(
            $this->context(['/opt/vortos/does-not-exist-' . bin2hex(random_bytes(6)) . '.env']),
        );

        self::assertSame(PreflightStatus::Skip, $finding->status);
    }

    public function test_no_env_files_declared_passes(): void
    {
        $finding = (new EnvFileReadabilityCheck())->check($this->context([]));

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    private function tempEnvFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vortos-envfile-');
        self::assertIsString($path);
        file_put_contents($path, "APP_ENV=production\n");
        $this->tmpFiles[] = $path;

        return $path;
    }

    /** @param list<string> $envFiles */
    private function context(array $envFiles): PreflightContext
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
            runtimeService: new RuntimeServiceSpec(envFiles: $envFiles),
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

        return new PreflightContext(
            $definition,
            $manifest,
            $state,
            new EnvironmentName('production'),
        );
    }
}
