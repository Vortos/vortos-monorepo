<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveChildDefinitionsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\DependencyInjection\Compiler\ContainerDumpabilityPass;

/**
 * B21 whole-framework regression: assemble EVERY discovered Vortos package's DI extension exactly as
 * {@see \Vortos\Foundation\Bootstrap\Container.php} does, merge them, and assert the
 * {@see ContainerDumpabilityPass} finds no non-dumpable object-instance service argument anywhere.
 *
 * This is the guard that would have caught the Alerts/Auth object-argument bugs (and any new one) in
 * CI instead of at the first prod HTTP boot. It runs only the merge + child-definition resolution +
 * the guard — not the full optimization pipeline — so it is independent of app-only service wiring
 * (deploy driver aliases, DB connectivity, …) while still exercising every real extension's `load()`.
 */
final class ContainerDumpsCleanTest extends TestCase
{
    public function test_full_framework_container_has_no_non_dumpable_arguments(): void
    {
        $projectRoot = $this->locateProjectRoot();
        if ($projectRoot === null) {
            $this->markTestSkipped('No assembled project root (vendor/ + config/) — nothing to assemble.');
        }

        // Seed the env the project's own config/*.php files require so every extension's load()
        // succeeds regardless of how bare the CI/host environment is (the config closures read $_ENV;
        // config/auth.php hard-throws without a JWT_SECRET). Dummy values only — nothing here connects.
        $seed = [
            'VORTOS_WRITE_DB_DSN' => 'pgsql://vortos:vortos@127.0.0.1:5432/vortos',
            'JWT_SECRET' => str_repeat('a', 64),
            'APP_NAME' => 'vortos-test',
        ];
        foreach ($seed as $key => $value) {
            $_ENV[$key] ??= $value;
        }
        $_ENV['VORTOS_READ_DB_DSN'] ??= $_ENV['VORTOS_WRITE_DB_DSN'];

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectRoot);
        $container->setParameter('kernel.env', 'prod');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.context', 'http');
        $container->setParameter('kernel.enable_routes', true);
        $container->setParameter('charset', 'UTF-8');
        $container->setParameter('kernel.log_path', $projectRoot . '/var/log');
        $container->setParameter('vortos.persistence.framework_table_mode', 'prefix');
        $container->register(Application::class, Application::class)
            ->setArguments(['Vortos', '1.0.0-alpha'])
            ->setPublic(true);

        $packages = $this->discoverPackages($projectRoot);
        if ($packages === []) {
            $this->markTestSkipped('No Vortos packages discovered from composer metadata.');
        }

        foreach ($packages as $class) {
            /** @var \Vortos\Foundation\Contract\PackageInterface $package */
            $package = new $class();
            $package->build($container);
            $extension = $package->getContainerExtension();
            if ($extension === null) {
                continue;
            }
            $container->registerExtension($extension);
            $container->loadFromExtension($extension->getAlias());
        }

        // Merge every extension (runs each load()). This depends on the project's config/*.php + env;
        // if a stripped environment can't complete the merge, skip rather than error — the always-on
        // ContainerDumpabilityPass in FoundationPackage still guards every real container compile.
        try {
            (new MergeExtensionConfigurationPass())->process($container);
            (new ResolveChildDefinitionsPass())->process($container);
        } catch (\Throwable $e) {
            $this->markTestSkipped('environment cannot merge all extensions here: ' . $e->getMessage());
        }

        // The assertion: the guard must not throw. If it does, its message lists every offender.
        (new ContainerDumpabilityPass())->process($container);

        $this->assertGreaterThan(
            100,
            count($container->getDefinitions()),
            'sanity: the full framework container should register many services',
        );
    }

    private function locateProjectRoot(): ?string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/vendor/autoload.php') && is_dir($dir . '/config')) {
                return $dir;
            }
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /** @return list<class-string> */
    private function discoverPackages(string $projectRoot): array
    {
        $discovered = [];
        $scan = static function (array $pkgData) use (&$discovered): void {
            $cls = $pkgData['extra']['vortos']['package'] ?? null;
            $order = $pkgData['extra']['vortos']['order'] ?? 999;
            if (is_string($cls) && class_exists($cls)) {
                $discovered[$cls] = ['class' => $cls, 'order' => $order];
            }
        };

        $installedJson = $projectRoot . '/vendor/composer/installed.json';
        if (is_file($installedJson)) {
            $installed = json_decode((string) file_get_contents($installedJson), true, 512, \JSON_THROW_ON_ERROR);
            foreach ($installed['packages'] ?? $installed as $pkg) {
                $scan($pkg);
            }
        }

        $rootComposer = $projectRoot . '/composer.json';
        if (is_file($rootComposer)) {
            $rootData = json_decode((string) file_get_contents($rootComposer), true, 512, \JSON_THROW_ON_ERROR);
            foreach ($rootData['repositories'] ?? [] as $repo) {
                if (($repo['type'] ?? '') !== 'path') {
                    continue;
                }
                $basePath = $projectRoot . '/' . rtrim((string) $repo['url'], '/');
                foreach (glob($basePath . '/composer.json') ?: [] as $file) {
                    $scan(json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR));
                }
                foreach (glob($basePath . '/src/*/composer.json') ?: [] as $file) {
                    $scan(json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR));
                }
            }
        }

        usort($discovered, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_map(static fn (array $e): string => $e['class'], $discovered);
    }
}
