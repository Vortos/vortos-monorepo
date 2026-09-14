<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Topology;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\BindAccess;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Topology\BindMountScopeRule;
use Vortos\Pipeline\Topology\TopologyPolicy;
use Vortos\Pipeline\Topology\TopologyViolation;

/**
 * RC-3 guard: every host path a service bind-mounts is accounted for, mounted only by its audience, no
 * wider than declared, and a mount containing the deploy dir hides it.
 */
final class BindMountScopeRuleTest extends TestCase
{
    /** The production mount shapes, as they must be written once RC-3 lands. */
    private const CLEAR = <<<'YAML'
        services:
          docker-socket-proxy:
            volumes:
              - /var/run/docker.sock:/var/run/docker.sock:ro
          otel-collector:
            volumes:
              - ./observability/collector/otel-collector-config.yaml:/etc/otelcol/config.yaml:ro
              - /:/hostfs:ro
              - /var/lib/docker/containers:/var/lib/docker/containers:ro
              - vortos-otelcol-storage:/var/lib/otelcol/storage
            tmpfs:
              - /hostfs/opt/vortos
          otel-collector-init:
            volumes:
              - vortos-otelcol-storage:/var/lib/otelcol/storage
          write_db:
            volumes:
              - write_db_data_v2:/var/lib/postgresql
              - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
              - wal_archive:/wal_archive
          backup-scheduler:
            volumes:
              - ./vortos-secrets.age:/app/vortos-secrets.age:ro
              - wal_archive:/wal_archive
        volumes:
          write_db_data_v2:
          wal_archive:
          vortos-otelcol-storage:
        YAML;

    private function policy(): TopologyPolicy
    {
        $set = static fn (string ...$names): ComposeServiceSet => new ComposeServiceSet($names);

        return new TopologyPolicy('/opt/vortos', [new BindMountScopeRule(
            '/opt/vortos',
            [
                'observability/collector/otel-collector-config.yaml' => $set('otel-collector'),
                'docker/postgres/init' => $set('write_db'),
                'vortos-secrets.age' => $set('backup-scheduler'),
            ],
            [
                '/var/run/docker.sock' => [$set('docker-socket-proxy'), BindAccess::ReadOnly],
                '/' => [$set('otel-collector'), BindAccess::ReadOnly],
                '/var/lib/docker/containers' => [$set('otel-collector'), BindAccess::ReadOnly],
            ],
        )]);
    }

    /** @return list<string> */
    private function kinds(string $yaml): array
    {
        return array_map(
            static fn (TopologyViolation $v): string => $v->kind->value . ':' . $v->service,
            $this->policy()->evaluate($yaml),
        );
    }

    public function test_the_production_shape_is_clear(): void
    {
        self::assertSame([], $this->kinds(self::CLEAR));
    }

    public function test_a_file_beside_the_topology_that_nothing_syncs_is_refused(): void
    {
        $yaml = str_replace("      - ./vortos-secrets.age:/app/vortos-secrets.age:ro\n", "      - ./vortos-secrets.age:/app/vortos-secrets.age:ro\n      - ./docker/backup/wal-shipper.sh:/usr/local/bin/wal-shipper:ro\n", self::CLEAR);

        self::assertSame(['undeclared:backup-scheduler'], $this->kinds($yaml));
    }

    public function test_an_undeclared_host_path_is_refused_and_a_declared_child_does_not_authorise_its_parent(): void
    {
        $yaml = str_replace('/var/lib/docker/containers:/var/lib/docker/containers:ro', '/var/lib/docker:/var/lib/docker:ro', self::CLEAR);

        self::assertSame(['undeclared:otel-collector', 'unconsumed:otel-collector'], $this->kinds($yaml));
    }

    public function test_a_synced_path_mounted_writable_is_refused(): void
    {
        $yaml = str_replace('./docker/postgres/init:/docker-entrypoint-initdb.d:ro', './docker/postgres/init:/docker-entrypoint-initdb.d', self::CLEAR);

        self::assertSame(['access_wider_than_declared:write_db'], $this->kinds($yaml));
    }

    public function test_a_host_bind_wider_than_declared_is_refused(): void
    {
        $yaml = str_replace('/var/run/docker.sock:/var/run/docker.sock:ro', '/var/run/docker.sock:/var/run/docker.sock', self::CLEAR);

        self::assertSame(['access_wider_than_declared:docker-socket-proxy'], $this->kinds($yaml));
    }

    public function test_a_declared_path_on_a_service_outside_its_audience_is_refused(): void
    {
        $yaml = str_replace("      - ./vortos-secrets.age:/app/vortos-secrets.age:ro\n", "      - ./vortos-secrets.age:/app/vortos-secrets.age:ro\n      - /var/run/docker.sock:/var/run/docker.sock:ro\n", self::CLEAR);

        self::assertSame(['service_not_allowed:backup-scheduler'], $this->kinds($yaml));
    }

    public function test_an_allowed_service_that_never_mounts_its_path_is_refused(): void
    {
        $yaml = str_replace("      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro\n", '', self::CLEAR);

        self::assertSame(['unconsumed:write_db'], $this->kinds($yaml));
    }

    /** The exposure that put every plaintext secret inside a third-party log agent. */
    public function test_the_host_root_without_a_shadow_over_the_deploy_dir_is_refused(): void
    {
        $yaml = str_replace("    tmpfs:\n      - /hostfs/opt/vortos\n", '', self::CLEAR);

        self::assertSame(['deploy_dir_exposed:otel-collector'], $this->kinds($yaml));
        self::assertStringContainsString('add a tmpfs over /hostfs/opt/vortos', $this->policy()->evaluate($yaml)[0]->detail);
    }

    public function test_a_shadow_over_an_ancestor_of_the_deploy_dir_or_in_long_syntax_also_hides_it(): void
    {
        $ancestor = str_replace('      - /hostfs/opt/vortos', '      - /hostfs/opt:size=1m', self::CLEAR);
        $long = str_replace("    tmpfs:\n      - /hostfs/opt/vortos\n", '', self::CLEAR);
        $long = str_replace('      - /:/hostfs:ro', "      - /:/hostfs:ro\n      - { type: tmpfs, target: /hostfs/opt/vortos }", $long);

        self::assertSame([], $this->kinds($ancestor));
        self::assertSame([], $this->kinds($long));
    }

    public function test_a_named_volume_that_binds_a_host_device_is_judged_as_a_bind(): void
    {
        $yaml = self::CLEAR . "\n  sneaky:\n    driver: local\n    driver_opts: { type: none, o: bind, device: /etc }\n";
        $yaml = str_replace("      - wal_archive:/wal_archive\n  backup-scheduler:", "      - wal_archive:/wal_archive\n      - sneaky:/host-etc:ro\n  backup-scheduler:", $yaml);

        self::assertSame(['undeclared:write_db'], $this->kinds($yaml));
    }

    public function test_long_syntax_binds_resolve_like_short_syntax(): void
    {
        $yaml = str_replace(
            '      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro',
            "      - { type: bind, source: ./docker/postgres/init/001_create_database.sql, target: /docker-entrypoint-initdb.d/001.sql, read_only: true }",
            self::CLEAR,
        );

        self::assertSame([], $this->kinds($yaml), 'a file inside a synced directory is covered by the directory');
    }

    public function test_interpolated_home_or_traversing_sources_and_unknown_types_are_unverifiable(): void
    {
        $yaml = str_replace("      - ./vortos-secrets.age:/app/vortos-secrets.age:ro\n", "      - ./vortos-secrets.age:/app/vortos-secrets.age:ro\n      - \${SECRETS}/x:/x:ro\n      - ~/.ssh:/ssh:ro\n      - ../escape:/escape:ro\n      - { type: npipe, source: x, target: /y }\n", self::CLEAR);

        self::assertSame(
            ['unverifiable:backup-scheduler', 'unverifiable:backup-scheduler', 'unverifiable:backup-scheduler', 'unverifiable:backup-scheduler'],
            $this->kinds($yaml),
        );
    }

    public function test_a_host_system_bind_inside_the_deploy_dir_is_refused_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BindMountScopeRule('/opt/vortos', [], ['/opt/vortos/age.env' => [new ComposeServiceSet(['x']), BindAccess::ReadOnly]]);
    }

    public function test_violations_name_the_rule_service_and_mount_but_never_contents(): void
    {
        $yaml = str_replace('./docker/postgres/init:/docker-entrypoint-initdb.d:ro', './docker/postgres/init:/docker-entrypoint-initdb.d', self::CLEAR);
        $violation = $this->policy()->evaluate($yaml)[0];

        self::assertStringStartsWith('[bind_mount_scope/access_wider_than_declared] service "write_db", "./docker/postgres/init:/docker-entrypoint-initdb.d": ', $violation->message());
    }
}
