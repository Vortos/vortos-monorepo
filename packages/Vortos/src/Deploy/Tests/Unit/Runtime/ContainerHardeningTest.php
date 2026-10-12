<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runtime\ContainerHardening;

final class ContainerHardeningTest extends TestCase
{
    public function test_locked_drops_every_capability_blocks_escalation_and_mounts_only_the_declared_paths(): void
    {
        $keys = ContainerHardening::locked(['/tmp', '/var/www/html/var'])->composeKeys();

        self::assertSame(['ALL'], $keys['cap_drop']);
        self::assertSame(['no-new-privileges:true'], $keys['security_opt']);
        self::assertTrue($keys['read_only']);
        self::assertSame(['/tmp', '/var/www/html/var'], $keys['tmpfs']);
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
        ContainerHardening::locked([$path]);
    }

    public function test_a_writable_path_is_declared_once(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContainerHardening::locked(['/tmp', '/tmp']);
    }
}
