<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Definition\PipelineDefinitionBuilder;
use Vortos\Pipeline\Model\BindAccess;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\FileOwner;
use Vortos\Pipeline\Model\HostEnvFileName;
use Vortos\Pipeline\Model\HostSystemBind;
use Vortos\Pipeline\Model\ProjectPath;
use Vortos\Pipeline\Model\RootOfTrustEnvFile;
use Vortos\Pipeline\Model\SyncedFileMode;
use Vortos\Pipeline\Model\SyncedHostPath;
use Vortos\Pipeline\Topology\BindMountScopeRule;
use Vortos\Pipeline\Topology\TopologyPolicy;

/**
 * RC-3: a pipeline definition refuses a bind-mount declaration it could not deliver or enforce.
 */
final class PipelineDefinitionHostBindsTest extends TestCase
{
    private static function synced(string $path, string ...$services): SyncedHostPath
    {
        return new SyncedHostPath(new ProjectPath($path), SyncedFileMode::WorldReadable, FileOwner::root(), new ComposeServiceSet($services === [] ? ['svc'] : $services));
    }

    private static function system(string $path): HostSystemBind
    {
        return new HostSystemBind($path, BindAccess::ReadOnly, new ComposeServiceSet(['otel-collector']), 'reason');
    }

    public function test_the_builder_propagates_every_declaration_and_the_policy_carries_the_rule(): void
    {
        $definition = PipelineDefinitionBuilder::create()
            ->syncComposeTopology(true, apply: true)
            ->syncedHostPaths(self::synced('docker/postgres/init', 'write_db'))
            ->hostSystemBinds(self::system('/'))
            ->build();

        self::assertSame('docker/postgres/init', $definition->syncedHostPaths[0]->path->value);
        self::assertSame('/', $definition->hostSystemBinds[0]->path);
        self::assertSame(
            [['path' => 'docker/postgres/init', 'mode' => '0644', 'owner' => '0:0', 'services' => ['write_db']]],
            $definition->toArray()['synced_host_paths'],
        );
        self::assertSame([['path' => '/', 'access' => 'ro', 'services' => ['otel-collector']]], $definition->toArray()['host_system_binds']);
        self::assertContains(BindMountScopeRule::NAME, TopologyPolicy::forDefinition($definition)->ruleNames());
    }

    public function test_synced_paths_need_the_topology_sync_that_delivers_them(): void
    {
        $this->expectExceptionMessage('delivered by the topology sync');

        new PipelineDefinition(syncedHostPaths: [self::synced('docker/postgres/init')]);
    }

    public function test_overlapping_synced_paths_are_refused(): void
    {
        $this->expectExceptionMessage('must not overlap');

        new PipelineDefinition(syncComposeTopology: true, syncedHostPaths: [self::synced('docker/postgres'), self::synced('docker/postgres/init')]);
    }

    public function test_the_topology_itself_is_not_a_synced_path(): void
    {
        $this->expectExceptionMessage('the topology itself');

        new PipelineDefinition(syncComposeTopology: true, syncedHostPaths: [self::synced('docker-compose.prod.yaml')]);
    }

    public function test_a_declared_env_file_is_never_copied_in_plaintext(): void
    {
        $this->expectExceptionMessage('delivered sealed');

        new PipelineDefinition(
            syncComposeTopology: true,
            rootOfTrustEnvFiles: [new RootOfTrustEnvFile(new HostEnvFileName('age.env'), new ComposeServiceSet(['svc']), 'identity')],
            syncedHostPaths: [self::synced('age.env')],
        );
    }

    public function test_a_host_system_bind_inside_the_deploy_dir_is_refused(): void
    {
        $this->expectExceptionMessage('inside the deploy dir');

        new PipelineDefinition(hostSystemBinds: [self::system('/opt/vortos/observability')]);
    }

    public function test_a_host_system_bind_is_declared_once(): void
    {
        $this->expectExceptionMessage('declared twice');

        new PipelineDefinition(hostSystemBinds: [self::system('/'), self::system('/')]);
    }

    public function test_untyped_config_entries_are_refused(): void
    {
        $this->expectExceptionMessage('SyncedHostPath instances');

        // @phpstan-ignore argument.type
        new PipelineDefinition(syncComposeTopology: true, syncedHostPaths: [['path' => 'docker']]);
    }
}
