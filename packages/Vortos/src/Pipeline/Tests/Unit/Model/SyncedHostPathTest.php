<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\BindAccess;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\HostSystemBind;
use Vortos\Pipeline\Model\ProjectPath;
use Vortos\Pipeline\Model\SyncedFileMode;

/** RC-3: bind-mount declarations refuse anything that could escape a root or widen access by typo. */
final class SyncedHostPathTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function badProjectPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc/passwd'];
        yield 'traversal' => ['docker/../../etc'];
        yield 'dot segment' => ['docker/./init'];
        yield 'hidden' => ['.env.prod'];
        yield 'hidden segment' => ['docker/.git/config'];
        yield 'trailing slash' => ['docker/postgres/'];
        yield 'metachar' => ['docker/init;id'];
        yield 'space' => ['docker/my init'];
    }

    #[DataProvider('badProjectPaths')]
    public function test_project_paths_refuse_escapes_and_hidden_files(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProjectPath($path);
    }

    public function test_project_path_containment_is_by_segment_not_by_prefix(): void
    {
        $init = new ProjectPath('docker/postgres/init');

        self::assertTrue($init->contains(new ProjectPath('docker/postgres/init')));
        self::assertTrue($init->contains(new ProjectPath('docker/postgres/init/001.sql')));
        self::assertFalse($init->contains(new ProjectPath('docker/postgres/initdb')));
    }

    /** @return iterable<string, array{string}> */
    public static function badHostPaths(): iterable
    {
        yield 'relative' => ['var/run/docker.sock'];
        yield 'traversal' => ['/var/../etc'];
        yield 'trailing slash' => ['/var/lib/'];
        yield 'metachar' => ['/var/run/$(id)'];
    }

    #[DataProvider('badHostPaths')]
    public function test_host_system_binds_refuse_unnormalised_paths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HostSystemBind($path, BindAccess::ReadOnly, new ComposeServiceSet(['x']), 'reason');
    }

    public function test_a_host_system_bind_must_say_why(): void
    {
        $this->expectExceptionMessage('must state why');

        new HostSystemBind('/', BindAccess::ReadOnly, new ComposeServiceSet(['otel-collector']), '  ');
    }

    public function test_the_host_root_is_a_valid_declaration(): void
    {
        self::assertSame('/', (new HostSystemBind('/', BindAccess::ReadOnly, new ComposeServiceSet(['otel-collector']), 'statfs for disk metrics'))->path);
    }

    public function test_no_synced_mode_grants_group_or_world_write(): void
    {
        foreach (SyncedFileMode::cases() as $mode) {
            self::assertSame(0, $mode->value & 0o022, $mode->name);
        }
        self::assertSame('0640', SyncedFileMode::GroupReadable->octal());
    }

    public function test_only_read_write_is_wider_than_read_only(): void
    {
        self::assertTrue(BindAccess::ReadWrite->isWiderThan(BindAccess::ReadOnly));
        self::assertFalse(BindAccess::ReadOnly->isWiderThan(BindAccess::ReadWrite));
        self::assertFalse(BindAccess::ReadOnly->isWiderThan(BindAccess::ReadOnly));
    }
}
