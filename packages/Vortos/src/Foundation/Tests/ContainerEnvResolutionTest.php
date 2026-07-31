<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\Runner;

/**
 * FB-45: a prod HTTP boot must never serve a container whose %env(...)% references are still
 * Symfony placeholder tokens.
 *
 * prod+http compiles with `compile(false)` on purpose — resolving at compile time would bake env
 * values into the dump on disk — and relies on the dumped container's getEnv() calls instead. The
 * defect was that {@see Runner::getCompiledContainer()} then RETURNED THAT BUILDER, so the worker
 * that wrote the dump, and every worker that lost the race for the dump lock, served the literal
 * string "env_<hash>_NAME_<hash>" for every env-backed argument in the application, for the rest of
 * its life. It reached browsers as a Mercure hub URL that was not a URL.
 *
 * Why the existing suite could not see it: every other test compiles containers directly, and a
 * test that compiles with resolution ON has already stepped around the bug. The only thing that
 * catches it is asserting on the container the BOOT PATH hands back, for a project that is
 * prod + http and has no dump yet — the state of every worker on every deploy.
 *
 * {@see test_the_dump_path_builder_really_does_hold_placeholders} pins the premise, so if Symfony
 * ever stops leaving tokens in an unresolved builder this suite says so rather than passing
 * vacuously.
 */
final class ContainerEnvResolutionTest extends TestCase
{
    private const PROBE_ENV = 'VORTOS_FB45_PROBE';
    private const PROBE_VALUE = 'https://hub.example.test/.well-known/mercure';

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        $_ENV[self::PROBE_ENV]    = self::PROBE_VALUE;
        $_SERVER[self::PROBE_ENV] = self::PROBE_VALUE;
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::PROBE_ENV], $_SERVER[self::PROBE_ENV]);

        foreach ($this->roots as $root) {
            $this->removeTree($root);
        }

        $this->roots = [];
    }

    public function test_prod_http_boot_resolves_env_references(): void
    {
        $runner = new ProbeRunner('prod', false, $this->makeProjectRoot(), 'http');

        $container = $this->boot($runner);

        $this->assertSame(
            self::PROBE_VALUE,
            $container->getParameter('probe'),
            'the container served to the application still holds an unresolved env placeholder',
        );
    }

    /**
     * The dump is not a by-product of booting — it IS the container that gets served. Returning the
     * builder alongside it is precisely what FB-45 did, so the type of what comes back is part of
     * the contract, not an implementation detail.
     */
    public function test_prod_http_boot_serves_the_dump_rather_than_the_builder(): void
    {
        $runner = new ProbeRunner('prod', false, $this->makeProjectRoot(), 'http');

        $container = $this->boot($runner);

        $this->assertNotInstanceOf(
            ContainerBuilder::class,
            $container,
            'prod+http served the ContainerBuilder it compiled for dumping',
        );
    }

    /** The second boot takes the warm path, and must be just as resolved as the cold one. */
    public function test_a_warm_boot_from_an_existing_dump_is_resolved(): void
    {
        $root = $this->makeProjectRoot();

        $cold = $this->boot(new ProbeRunner('prod', false, $root, 'http'));
        $warm = $this->boot(new ProbeRunner('prod', false, $root, 'http'));

        $this->assertSame(self::PROBE_VALUE, $cold->getParameter('probe'));
        $this->assertSame(self::PROBE_VALUE, $warm->getParameter('probe'));
        $this->assertNotSame($cold, $warm, 'the warm boot returned the cold boot instance');
    }

    /**
     * A dump the process cannot produce must not fall back to serving the unresolved builder. The
     * unwritable cache dir stands in for every reason dumping can fail — a read-only volume, a
     * dumper error, or losing the lock and finding no dump to take.
     */
    public function test_a_boot_that_cannot_dump_still_resolves_env_references(): void
    {
        $root = $this->makeProjectRoot();
        $cache = $root . '/var/cache';

        mkdir($cache, 0755, true);

        if (!chmod($cache, 0500) || is_writable($cache)) {
            $this->markTestSkipped('cannot make a directory unwritable here (running as root?)');
        }

        try {
            $container = $this->boot(new ProbeRunner('prod', false, $root, 'http'));

            $this->assertSame(
                self::PROBE_VALUE,
                $container->getParameter('probe'),
                'a boot that could not dump served placeholder tokens instead of recompiling',
            );
        } finally {
            chmod($cache, 0755);
        }
    }

    /** Non-http contexts have no dump, so they must resolve in-process. */
    public function test_cli_boot_resolves_env_references(): void
    {
        $container = $this->boot(new ProbeRunner('prod', false, $this->makeProjectRoot(), 'cli'));

        $this->assertSame(self::PROBE_VALUE, $container->getParameter('probe'));
    }

    /**
     * Two projects booted in one process used to mean two dumps declaring the same class name, and
     * a fatal class redeclaration on the second require. Per-hash naming makes them two classes.
     */
    public function test_two_projects_can_be_booted_in_one_process(): void
    {
        $first  = $this->boot(new ProbeRunner('prod', false, $this->makeProjectRoot(), 'http'));
        $second = $this->boot(new ProbeRunner('prod', false, $this->makeProjectRoot(), 'http'));

        $this->assertSame(self::PROBE_VALUE, $first->getParameter('probe'));
        $this->assertSame(self::PROBE_VALUE, $second->getParameter('probe'));
        $this->assertNotSame($first::class, $second::class, 'both dumps declared the same class');
    }

    /**
     * A dump this framework version cannot load — one written by an older dump format, or a
     * truncated file — must be discarded and rebuilt, not served and not fatal. This is the state
     * every host is in the first time it boots a framework whose DUMP_FORMAT has moved while the
     * config hash has not (a framework-only upgrade over a warm cache directory).
     */
    public function test_an_unloadable_dump_is_discarded_and_rebuilt(): void
    {
        $root   = $this->makeProjectRoot();
        $runner = new ProbeRunner('prod', false, $root, 'http');

        $dumpPath = $this->invoke($runner, 'dumpPathFor', $this->invoke($runner, 'configHash'));

        mkdir(dirname($dumpPath), 0755, true);
        file_put_contents($dumpPath, '<?php class VortosDumpFromAnOlderFormat {}');

        $container = $this->boot($runner);

        $this->assertSame(
            self::PROBE_VALUE,
            $container->getParameter('probe'),
            'an unloadable dump was not rebuilt',
        );
        $this->assertStringNotContainsString(
            'VortosDumpFromAnOlderFormat',
            (string) file_get_contents($dumpPath),
            'the unusable dump was left on disk for the next boot to trip over',
        );
    }

    /**
     * The premise this whole file rests on: a builder compiled the way the dump path compiles it
     * really does still contain placeholder tokens, so the assertions above are not vacuous.
     */
    public function test_the_dump_path_builder_really_does_hold_placeholders(): void
    {
        $builder = ProbeRunner::probeContainer();
        $builder->compile(false);

        $this->assertMatchesRegularExpression(
            '/^env_[0-9a-f]+_' . self::PROBE_ENV . '_[0-9a-f]+$/',
            (string) $builder->getParameter('probe'),
            'Symfony no longer leaves placeholder tokens in an unresolved builder — this suite ' .
            'was written around that behaviour and needs revisiting',
        );
    }

    private function boot(Runner $runner): \Symfony\Component\DependencyInjection\Container
    {
        return $this->invoke($runner, 'getCompiledContainer');
    }

    private function invoke(Runner $runner, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($runner, $method))->invoke($runner, ...$args);
    }

    /** A throwaway project root. Distinct config content keeps each one's config hash distinct. */
    private function makeProjectRoot(): string
    {
        $root = sys_get_temp_dir() . '/vortos-fb45-' . bin2hex(random_bytes(6));

        mkdir($root . '/config', 0755, true);
        file_put_contents($root . '/config/probe.php', '<?php return ' . var_export($root, true) . ';');

        $this->roots[] = $root;

        return $root;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        @chmod($path, 0755);
        @rmdir($path);
    }
}

/**
 * A Runner whose container is one parameter wide.
 *
 * It overrides only where the definitions come from; every decision under test — which contexts
 * dump, what may be returned, what happens when dumping fails — is the real Runner's.
 */
final class ProbeRunner extends Runner
{
    public static function probeContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('probe', '%env(VORTOS_FB45_PROBE)%');

        return $container;
    }

    protected function buildContainer(bool $resolveEnvPlaceholders): ContainerBuilder
    {
        $container = self::probeContainer();
        $container->compile($resolveEnvPlaceholders);

        return $container;
    }
}
