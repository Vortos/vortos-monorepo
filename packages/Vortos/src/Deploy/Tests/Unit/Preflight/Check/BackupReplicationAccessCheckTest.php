<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Backup\Doctor\ReplicationAccessInspector;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Preflight\Check\BackupReplicationAccessCheck;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;

/**
 * The gate is right to block a deploy when the database will not accept a replication connection.
 * It is not right to say that when the only thing it discovered is that it has no client to ask
 * with — which is the normal state of the lean deploy image this framework's own STAGE-F-1 pattern
 * produces, and which blocked a production deploy on a cluster whose pg_hba was correct.
 */
final class BackupReplicationAccessCheckTest extends TestCase
{
    use PreflightTestFactory;

    private const DSN = 'pgsql://app:pw@db:5432/app';

    private function registry(BackupKind ...$kinds): BackupScheduleRegistry
    {
        $schedules = [];
        foreach ($kinds as $i => $kind) {
            $schedules[] = new BackupSchedule(
                'sched-' . $i,
                DatabaseEngine::Postgres,
                $kind,
                'production',
                '0 2 * * 0',
                BackupScheduleType::Backup,
            );
        }

        return new BackupScheduleRegistry($schedules);
    }

    private function check(
        \Closure $probe,
        bool $toolchainExternal = false,
        BackupKind ...$kinds,
    ): BackupReplicationAccessCheck {
        return new BackupReplicationAccessCheck(
            new ReplicationAccessInspector($probe),
            $this->registry(...($kinds ?: [BackupKind::PhysicalBase])),
            'postgres',
            self::DSN,
            $toolchainExternal,
        );
    }

    private function refuses(): \Closure
    {
        return static fn (string $dsn): array => [
            'ok' => false,
            'error' => 'FATAL: no pg_hba entry for replication connection',
        ];
    }

    private function noClient(): \Closure
    {
        return static fn (string $dsn): array => [
            'ok' => false,
            'error' => 'psql is not installed on this node',
            'client_missing' => true,
        ];
    }

    public function test_id_and_category_are_stable(): void
    {
        $check = $this->check(static fn (string $d): array => ['ok' => true, 'error' => null]);

        $this->assertSame('backup.replication_access', $check->id());
        $this->assertSame(PreflightCategory::Capability, $check->category());
    }

    public function test_it_still_blocks_a_deploy_when_the_database_refuses_replication(): void
    {
        $finding = $this->check($this->refuses())->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('pg_hba', $finding->remediation);
    }

    /**
     * The regression this test exists for: declaring a physical_base schedule on a lean-image deploy
     * turned every deploy red with a message accusing the database, on a cluster that had been
     * taking base backups successfully for weeks.
     */
    public function test_it_defers_when_the_toolchain_is_declared_external_via_env(): void
    {
        $finding = $this->check($this->noClient(), toolchainExternal: true)->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
        $this->assertStringContainsString('external', $finding->summary);
    }

    public function test_config_deploy_wins_over_the_env_default(): void
    {
        $definition = DeploymentDefinition::create()
            ->host('fake-target')->registry('fake-registry')->credential('fake-credential')
            ->backupToolchainExternal(true)
            ->build();

        $finding = $this->check($this->noClient(), toolchainExternal: false)
            ->check($this->context(definition: $definition));

        $this->assertSame(PreflightStatus::Pass, $finding->status);
    }

    /**
     * Without the external declaration a missing client is still a failure — but it must be reported
     * as itself, not as a refused connection, or the operator is sent to fix a correct pg_hba.conf.
     */
    public function test_a_missing_client_is_never_reported_as_a_refused_connection(): void
    {
        $finding = $this->check($this->noClient())->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('could not be verified', $finding->detail);
        $this->assertStringNotContainsString('refused a replication connection', $finding->detail);
        $this->assertStringContainsString('backupToolchainExternal', $finding->remediation);
    }

    public function test_a_logical_only_setup_is_not_gated_at_all(): void
    {
        $finding = $this->check($this->refuses(), false, BackupKind::LogicalFull)->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
    }
}
