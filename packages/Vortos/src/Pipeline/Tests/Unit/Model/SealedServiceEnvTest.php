<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\FileOwner;
use Vortos\Pipeline\Model\HostEnvFileName;
use Vortos\Pipeline\Model\RootOfTrustEnvFile;
use Vortos\Pipeline\Model\SealedServiceEnv;
use Vortos\Pipeline\Model\SecretFileMode;

/**
 * RC-4: every field of a per-service secret declaration is interpolated into the deploy one-shot's
 * command line or compared with the topology, so each refuses anything it cannot use safely.
 */
final class SealedServiceEnvTest extends TestCase
{
    public function test_a_valid_declaration_carries_its_fields(): void
    {
        $env = new SealedServiceEnv(
            sealedPath: 'deploy/secrets/backup-r2.env.sealed',
            target: new HostEnvFileName('backup-r2.env'),
            mode: SecretFileMode::OwnerReadWrite,
            owner: FileOwner::root(),
            services: new ComposeServiceSet(['backup-scheduler']),
        );

        self::assertSame('backup-r2.env', $env->target->value);
        self::assertSame('0600', $env->mode->octal());
        self::assertSame('0:0', $env->owner->toString());
        self::assertTrue($env->services->contains('backup-scheduler'));
        self::assertFalse($env->services->contains('app-blue'));
    }

    /** @return iterable<string, array{string}> */
    public static function badSealedPaths(): iterable
    {
        yield 'absolute' => ['/deploy/secrets/x.env.sealed'];
        yield 'leading traversal' => ['../x.env.sealed'];
        yield 'inner traversal' => ['deploy/../x.env.sealed'];
        yield 'dot segment' => ['deploy/./x.env.sealed'];
        yield 'not sealed' => ['deploy/secrets/x.env'];
        yield 'whitespace' => ['deploy/secrets/x y.sealed'];
        yield 'command substitution' => ['deploy/$(id).sealed'];
        yield 'separator' => ['deploy/x;rm.sealed'];
    }

    #[DataProvider('badSealedPaths')]
    public function test_refuses_an_unsafe_sealed_path(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SealedServiceEnv($path, new HostEnvFileName('x.env'), SecretFileMode::OwnerRead, FileOwner::root(), new ComposeServiceSet(['svc']));
    }

    /** @return iterable<string, array{string}> */
    public static function badTargets(): iterable
    {
        yield 'the app-wide env' => ['.env.prod'];
        yield 'hidden' => ['.secret.env'];
        yield 'nested' => ['secrets/x.env'];
        yield 'traversal' => ['../x.env'];
        yield 'not an env file' => ['x.txt'];
        yield 'metachar' => ['x$.env'];
        yield 'empty' => [''];
    }

    #[DataProvider('badTargets')]
    public function test_refuses_an_unsafe_target(string $target): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HostEnvFileName($target);
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function badServiceSets(): iterable
    {
        yield 'empty' => [[]];
        yield 'duplicate' => [['a', 'a']];
        yield 'bad name' => [['app blue']];
        yield 'not a string' => [[1]];
    }

    /** @param list<mixed> $names */
    #[DataProvider('badServiceSets')]
    public function test_refuses_an_unusable_audience(array $names): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type
        new ComposeServiceSet($names);
    }

    public function test_refuses_a_negative_owner(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FileOwner(-1, 0);
    }

    public function test_modes_are_owner_only(): void
    {
        self::assertSame(['0400', '0600'], array_map(static fn (SecretFileMode $m): string => $m->octal(), SecretFileMode::cases()));
    }

    public function test_a_root_of_trust_file_must_say_why_it_is_not_sealed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RootOfTrustEnvFile(new HostEnvFileName('age.env'), new ComposeServiceSet(['backup-scheduler']), '  ');
    }
}
