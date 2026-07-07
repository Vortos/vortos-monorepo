<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Doctor\BackupToolchainInspector;
use Vortos\Deploy\Preflight\Check\BackupToolchainCheck;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;

final class BackupToolchainCheckTest extends TestCase
{
    use PreflightTestFactory;

    public function test_id_and_category_are_stable(): void
    {
        $check = new BackupToolchainCheck($this->inspector($this->presentProbe()), 'postgres');

        $this->assertSame('backup.toolchain', $check->id());
        $this->assertSame(PreflightCategory::Capability, $check->category());
    }

    public function test_skips_when_no_engine_configured(): void
    {
        foreach ([null, '', '   '] as $configured) {
            $check = new BackupToolchainCheck($this->inspector($this->presentProbe()), $configured);
            $finding = $check->check($this->context());

            $this->assertSame(PreflightStatus::Skip, $finding->status);
        }
    }

    public function test_passes_when_toolchain_present(): void
    {
        $check = new BackupToolchainCheck($this->inspector($this->presentProbe()), 'postgres');
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
        $this->assertStringContainsString('postgres', $finding->summary);
    }

    public function test_fails_when_a_required_binary_is_missing(): void
    {
        $probe = static fn (string $b): ?array => $b === 'pg_dump' ? null : ['path' => "/usr/bin/{$b}", 'major' => 18];
        $check = new BackupToolchainCheck($this->inspector($probe), 'postgres');
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('pg_dump not found', $finding->detail);
        $this->assertNotSame('', $finding->remediation);
    }

    public function test_fails_when_engine_value_is_unknown(): void
    {
        $check = new BackupToolchainCheck($this->inspector($this->presentProbe()), 'cassandra');
        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('no known backup engine', $finding->summary);
    }

    public function test_external_toolchain_passes_informationally_even_when_binaries_missing(): void
    {
        // R8-2: engine configured, toolchain external. The deploy image is lean by design; a missing
        // pg_dump HERE is not a failure — it runs on the backup role. Pass, informationally.
        $missing = static fn (string $b): ?array => null;
        $check = new BackupToolchainCheck($this->inspector($missing), 'postgres', toolchainExternal: true);

        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
        $this->assertStringContainsString('external', $finding->summary);
    }

    public function test_external_toolchain_still_validates_the_engine_name(): void
    {
        // Fail-closed even in external mode: a bogus engine name is still a hard error.
        $check = new BackupToolchainCheck($this->inspector($this->presentProbe()), 'cassandra', toolchainExternal: true);

        $finding = $check->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('no known backup engine', $finding->summary);
    }

    public function test_external_flag_false_keeps_the_in_image_assertion(): void
    {
        $probe = static fn (string $b): ?array => $b === 'pg_dump' ? null : ['path' => "/usr/bin/{$b}", 'major' => 18];
        $check = new BackupToolchainCheck($this->inspector($probe), 'postgres', toolchainExternal: false);

        $this->assertSame(PreflightStatus::Fail, $check->check($this->context())->status);
    }

    public function test_config_external_wins_over_env_default(): void
    {
        // config/deploy.php ->backupToolchainExternal(true) wins even though the env-derived default
        // ($toolchainExternal) is false and the binary is missing.
        $missing = static fn (string $b): ?array => null;
        $check = new BackupToolchainCheck($this->inspector($missing), 'postgres', toolchainExternal: false);

        $definition = \Vortos\Deploy\Definition\DeploymentDefinition::create()
            ->host('fake-target')->registry('fake-registry')->credential('fake-credential')
            ->backupToolchainExternal(true)
            ->build();

        $finding = $check->check($this->context(definition: $definition));

        $this->assertSame(PreflightStatus::Pass, $finding->status);
        $this->assertStringContainsString('external', $finding->summary);
    }

    private function presentProbe(): \Closure
    {
        return static fn (string $b): ?array => ['path' => "/usr/bin/{$b}", 'major' => 18];
    }

    private function inspector(\Closure $probe): BackupToolchainInspector
    {
        return new BackupToolchainInspector($probe);
    }
}
