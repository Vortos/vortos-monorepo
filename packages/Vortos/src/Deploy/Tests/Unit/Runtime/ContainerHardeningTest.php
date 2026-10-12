<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runtime\ContainerHardening;

final class ContainerHardeningTest extends TestCase
{
    public function test_locked_drops_every_capability_blocks_escalation_and_mounts_only_the_declared_paths(): void
    {
        $keys = ContainerHardening::locked(['/tmp', '/var/www/html/var'], 1000, 1000)->composeKeys();

        self::assertSame(['ALL'], $keys['cap_drop']);
        self::assertSame(['no-new-privileges:true'], $keys['security_opt']);
        self::assertTrue($keys['read_only']);
        // Owned by the process's user: a tmpfs is created root-owned, and the app could not write it.
        self::assertSame(['/tmp:uid=1000,gid=1000,mode=0700', '/var/www/html/var:uid=1000,gid=1000,mode=0700'], $keys['tmpfs']);
        self::assertArrayNotHasKey('cap_add', $keys, 'a color serves PHP on a high port and needs no capability');
    }

    public function test_none_leaves_the_service_as_docker_would(): void
    {
        self::assertSame([], ContainerHardening::none()->composeKeys());
    }

    /** @return iterable<string, array{string}> */
    public static function badPaths(): iterable
    {
        yield 'relative' => ['tmp'];
        yield 'mount options smuggled in' => ['/tmp:exec'];
        yield 'whitespace' => ['/tmp /etc'];
    }

    /** @dataProvider badPaths */
    public function test_a_writable_path_must_be_a_plain_absolute_path(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContainerHardening::locked([$path], 1000, 1000);
    }

    public function test_a_writable_path_is_declared_once(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContainerHardening::locked(['/tmp', '/tmp'], 1000, 1000);
    }
}
