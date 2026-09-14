<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Topology;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Topology\SyncedPathSpec;

/** RC-3: the root-run sync re-validates every argument instead of trusting what generated it. */
final class SyncedPathSpecTest extends TestCase
{
    public function test_parses_the_generated_form(): void
    {
        $spec = SyncedPathSpec::parse('vortos-secrets.age@0640@1001:1000@backup-scheduler,worker');

        self::assertSame('vortos-secrets.age', $spec->path);
        self::assertSame(0o640, $spec->mode);
        self::assertSame(1001, $spec->uid);
        self::assertSame(1000, $spec->gid);
        self::assertSame(['backup-scheduler', 'worker'], $spec->services);
    }

    public function test_an_executable_gets_execute_only_where_it_may_be_read(): void
    {
        self::assertSame(0o755, SyncedPathSpec::parse('a@0644@0:0@s')->fileMode(true));
        self::assertSame(0o750, SyncedPathSpec::parse('a@0640@0:0@s')->fileMode(true));
        self::assertSame(0o700, SyncedPathSpec::parse('a@0600@0:0@s')->fileMode(true));
        self::assertSame(0o640, SyncedPathSpec::parse('a@0640@0:0@s')->fileMode(false));
        self::assertSame(0o750, SyncedPathSpec::parse('a@0640@0:0@s')->directoryMode());
    }

    /** @return iterable<string, array{string}> */
    public static function badSpecs(): iterable
    {
        yield 'missing part' => ['docker/init@0644@0:0'];
        yield 'extra part' => ['docker/init@0644@0:0@s@x'];
        yield 'traversal' => ['docker/../../etc@0644@0:0@s'];
        yield 'absolute' => ['/etc@0644@0:0@s'];
        yield 'hidden' => ['.env.prod@0600@0:0@s'];
        yield 'world writable' => ['docker/init@0666@0:0@s'];
        yield 'setuid' => ['docker/init@4755@0:0@s'];
        yield 'named owner' => ['docker/init@0644@root:root@s'];
        yield 'bad service' => ['docker/init@0644@0:0@s;id'];
        yield 'empty service' => ['docker/init@0644@0:0@'];
    }

    #[DataProvider('badSpecs')]
    public function test_refuses_anything_that_could_escape_or_widen(string $spec): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SyncedPathSpec::parse($spec);
    }
}
