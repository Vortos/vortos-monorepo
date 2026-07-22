<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Drill;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\Container\ContainerHandle;
use Vortos\Backup\Drill\Container\ContainerRuntimeInterface;
use Vortos\Backup\Drill\Container\ContainerSpec;
use Vortos\Backup\Drill\Container\DockerEngineContainerRuntime;
use Vortos\Backup\Drill\DrillEnvironment;
use Vortos\Backup\Drill\Driver\Postgres\ContainerizedDatabaseProvisioner;
use Vortos\Backup\Drill\Driver\Postgres\EphemeralDatabaseProvisioner;

final class ContainerizedDatabaseProvisionerTest extends TestCase
{
    public function test_rejects_a_non_postgres_engine(): void
    {
        $provisioner = new ContainerizedDatabaseProvisioner(new FakeContainerRuntime());

        $this->expectException(RuntimeException::class);
        $provisioner->provision(DatabaseEngine::Mongo);
    }

    /** Teardown must destroy the container it created — the whole promise of a disposable drill. */
    public function test_teardown_removes_the_container(): void
    {
        $runtime = new FakeContainerRuntime();
        $provisioner = new ContainerizedDatabaseProvisioner($runtime);

        $provisioner->teardown(new DrillEnvironment('pgsql://x', 'container-abc'));

        $this->assertSame(['container-abc'], $runtime->removed);
    }

    /**
     * The case teardown structurally cannot cover: a hard kill between run and remove leaves an
     * orphan forever. Provisioning must sweep the previous run's leftovers on the way in.
     */
    public function test_provision_sweeps_orphans_from_a_previous_killed_run(): void
    {
        $runtime = new FakeContainerRuntime(failReady: true);
        $provisioner = new ContainerizedDatabaseProvisioner($runtime, readyTimeoutSeconds: 0);

        try {
            $provisioner->provision(DatabaseEngine::Postgres);
        } catch (\Throwable) {
            // readiness cannot succeed without a real database; the sweep is what is under test
        }

        $this->assertSame([ContainerizedDatabaseProvisioner::ORPHAN_LABEL], $runtime->sweptLabels);
    }

    /** A container that never becomes ready must not be left running. */
    public function test_a_container_that_never_becomes_ready_is_removed(): void
    {
        $runtime = new FakeContainerRuntime(failReady: true);
        $provisioner = new ContainerizedDatabaseProvisioner($runtime, readyTimeoutSeconds: 0);

        try {
            $provisioner->provision(DatabaseEngine::Postgres);
            $this->fail('expected provisioning to fail when the container never becomes ready');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNotEmpty($runtime->removed, 'the un-ready container must be cleaned up, not leaked');
    }

    public function test_container_is_labelled_and_data_lives_on_tmpfs(): void
    {
        $runtime = new FakeContainerRuntime(failReady: true);
        $provisioner = new ContainerizedDatabaseProvisioner($runtime, readyTimeoutSeconds: 0);

        try {
            $provisioner->provision(DatabaseEngine::Postgres);
        } catch (\Throwable) {
        }

        $spec = $runtime->lastSpec;
        $this->assertNotNull($spec);
        $this->assertArrayHasKey(ContainerizedDatabaseProvisioner::ORPHAN_LABEL, $spec->labels);
        $this->assertSame('/var/lib/postgresql/data', $spec->tmpfsPath);
        // Credentials must be generated per drill, never a fixed default.
        $this->assertNotSame('postgres', $spec->env['POSTGRES_PASSWORD'] ?? 'postgres');
    }

    /** Mounting the real Docker socket into an app container is root-on-host; refuse it outright. */
    public function test_engine_runtime_refuses_a_raw_docker_socket(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/socket-proxy/');

        new DockerEngineContainerRuntime('unix:///var/run/docker.sock');
    }

    public function test_engine_runtime_accepts_the_socket_proxy_endpoint(): void
    {
        $runtime = new DockerEngineContainerRuntime('tcp://docker-socket-proxy:2375');

        $this->assertInstanceOf(DockerEngineContainerRuntime::class, $runtime);
    }

    /**
     * BK-12 regression. The old substring guard passed this DSN cleanly because it contains none of
     * 'production' / 'prod-db' / 'primary-db' — while `write_db` was in fact the production primary.
     * This is the real production DSN from the 2026-07 audit.
     */
    public function test_same_server_provisioner_refuses_the_production_primary_by_topology(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/same host:port/');

        new EphemeralDatabaseProvisioner(
            drillDsn: 'pgsql://sqoura:pw@write_db:5432/drill',
            primaryDsn: 'pgsql://sqoura:pw@write_db:5432/sqoura',
        );
    }

    public function test_same_server_provisioner_allows_a_genuinely_separate_host(): void
    {
        $provisioner = new EphemeralDatabaseProvisioner(
            drillDsn: 'pgsql://sqoura:pw@drill_db:5432/drill',
            primaryDsn: 'pgsql://sqoura:pw@write_db:5432/sqoura',
        );

        $this->assertInstanceOf(EphemeralDatabaseProvisioner::class, $provisioner);
    }

    public function test_shared_host_can_be_opted_into_explicitly(): void
    {
        $provisioner = new EphemeralDatabaseProvisioner(
            drillDsn: 'pgsql://sqoura:pw@write_db:5432/drill',
            primaryDsn: 'pgsql://sqoura:pw@write_db:5432/sqoura',
            allowSharedHost: true,
        );

        $this->assertInstanceOf(EphemeralDatabaseProvisioner::class, $provisioner);
    }
}

final class FakeContainerRuntime implements ContainerRuntimeInterface
{
    /** @var list<string> */
    public array $removed = [];
    /** @var list<string> */
    public array $sweptLabels = [];
    /** @var list<string> */
    public array $pulled = [];
    public ?ContainerSpec $lastSpec = null;

    public function __construct(private readonly bool $failReady = false) {}

    public function ensureImage(string $image): void
    {
        $this->pulled[] = $image;
    }

    public function run(ContainerSpec $spec): ContainerHandle
    {
        $this->lastSpec = $spec;

        // An unroutable host so the readiness probe fails fast instead of waiting on DNS.
        return new ContainerHandle('container-' . $spec->name, $spec->name, $this->failReady ? '127.0.0.1' : $spec->name);
    }

    public function remove(ContainerHandle $handle): void
    {
        $this->removed[] = $handle->id;
    }

    public function removeOrphans(string $label, ?string $exceptId = null): int
    {
        $this->sweptLabels[] = $label;

        return 0;
    }
}
