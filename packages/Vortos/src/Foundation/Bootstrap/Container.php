<?php

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

// $projectRoot is injected by Runner before include
$container = new ContainerBuilder();

$container->setParameter('kernel.project_dir', $projectRoot);
$container->setParameter('charset', 'UTF-8');
$container->setParameter('kernel.log_path', $projectRoot . '/var/log');

$container->register(Application::class, Application::class)
    ->setArguments(['Vortos', '1.0.0-alpha'])
    ->setPublic(true);

// Auto-discover packages via extra.vortos.package
$discovered = [];

$scanComposerFile = function (array $pkgData) use (&$discovered): void {
    $packageClass = $pkgData['extra']['vortos']['package'] ?? null;
    $order = $pkgData['extra']['vortos']['order'] ?? 999;
    if ($packageClass && class_exists($packageClass)) {
        $discovered[$packageClass] = ['class' => $packageClass, 'order' => $order];
    }
};

// Source 1: installed.json (Packagist-installed packages)
$installedJson = $projectRoot . '/vendor/composer/installed.json';
if (file_exists($installedJson)) {
    $installed = json_decode(file_get_contents($installedJson), true);
    foreach ($installed['packages'] ?? $installed as $pkg) {
        $scanComposerFile($pkg);
    }
}

// Source 2: path repositories — scan their composer.json files
$rootComposer = $projectRoot . '/composer.json';
if (file_exists($rootComposer)) {
    $rootData = json_decode(file_get_contents($rootComposer), true);
    foreach ($rootData['repositories'] ?? [] as $repo) {
        if (($repo['type'] ?? '') !== 'path') {
            continue;
        }
        $basePath = $projectRoot . '/' . rtrim($repo['url'], '/');
        // Direct composer.json
        foreach (glob($basePath . '/composer.json') as $file) {
            $scanComposerFile(json_decode(file_get_contents($file), true) ?? []);
        }
        // Sub-packages (monorepo pattern: packages/Vendor/src/*/composer.json)
        foreach (glob($basePath . '/src/*/composer.json') as $file) {
            $scanComposerFile(json_decode(file_get_contents($file), true) ?? []);
        }
    }
}

usort($discovered, fn($a, $b) => $a['order'] <=> $b['order']);

foreach ($discovered as $entry) {
    $package = new $entry['class']();
    $package->build($container);
    $extension = $package->getContainerExtension();
    $container->registerExtension($extension);
    $container->loadFromExtension($extension->getAlias());
}

// Load application services — from project root config/
$loader = new PhpFileLoader($container, new FileLocator($projectRoot . '/config'));
$loader->load('services.php');

// Load framework services — from package's own config/ if it exists
$frameworkConfig = __DIR__ . '/../../../config/services.php';
if (file_exists($frameworkConfig)) {
    $loader2 = new PhpFileLoader($container, new FileLocator(dirname($frameworkConfig)));
    $loader2->load('services.php');
}

return $container;
