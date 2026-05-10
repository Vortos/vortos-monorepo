<?php
// Monorepo only — not published to skeleton or Packagist
$vendorDir = __DIR__ . '/../vendor/vortos';

$packages = [
    'vortos-auth'              => 'Auth',
    'vortos-authorization'     => 'Authorization',
    'vortos-cache'             => 'Cache',
    'vortos-cqrs'              => 'Cqrs',
    'vortos-domain'            => 'Domain',
    'vortos-foundation'        => 'Foundation',
    'vortos-http'              => 'Http',
    'vortos-logger'            => 'Logger',
    'vortos-messaging'         => 'Messaging',
    'vortos-persistence'       => 'Persistence',
    'vortos-persistence-dbal'  => 'PersistenceDbal',
    'vortos-persistence-mongo' => 'PersistenceMongo',
    'vortos-tracing'           => 'Tracing',
    'vortos-migration'         => 'Migration',
    'vortos-make'              => 'Make',
    'vortos-debug'             => 'Debug',
    'vortos-persistence-orm'   => 'PersistenceOrm',
    'vortos-feature-flags'     => 'FeatureFlags',
    'vortos-docker'            => 'Docker',
    'vortos-setup'             => 'Setup',
    'vortos-metrics'           => 'Metrics',
    'vortos-config'           => 'Config',
    'vortos-mcp'           => 'Mcp',
];

foreach ($packages as $vendorName => $srcName) {
    $link   = $vendorDir . '/' . $vendorName;
    $target = '../../packages/Vortos/src/' . $srcName;

    if (is_link($link) || is_file($link)) {
        unlink($link);
    } elseif (is_dir($link)) {
        removeDirectory($link);
    }

    if (!@symlink($target, $link)) {
        copyDirectory(__DIR__ . '/../packages/Vortos/src/' . $srcName, $link);
    }
    echo "Linked: vendor/vortos/{$vendorName}\n";
}

function removeDirectory(string $dir): void
{
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

function copyDirectory(string $source, string $dest): void
{
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    ) as $item) {
        $target = $dest . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($source) + 1);

        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }
            continue;
        }

        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        copy($item->getPathname(), $target);
    }
}
