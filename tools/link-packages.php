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
];

foreach ($packages as $vendorName => $srcName) {
    $link   = $vendorDir . '/' . $vendorName;
    $target = '../../packages/Vortos/src/' . $srcName;

    if (is_link($link)) unlink($link);
    elseif (is_dir($link)) exec('rm -rf ' . escapeshellarg($link));

    symlink($target, $link);
    echo "Linked: vendor/vortos/{$vendorName}\n";
}
