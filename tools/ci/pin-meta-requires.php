<?php

declare(strict_types=1);

/**
 * Pins the framework meta-package's sibling requires to the exact version being released.
 *
 * ## Why this has to exist
 *
 * `packages/Vortos` is split twice over: the whole directory becomes `vortos-framework`,
 * and each `packages/Vortos/src/X` becomes its own `vortos-x`. That is deliberate, not
 * an accident of the splitter — the container's service discovery globs the meta-package's
 * copy of the tree:
 *
 *     $services->load('Vortos\\', '../src/')   // config/services.php in vortos-framework
 *
 * while PSR-4 resolves each class from its own split package. So one package decides WHICH
 * services exist and a different package supplies WHAT they are.
 *
 * With a floating `^1.0` requirement those two can resolve to different releases, and they
 * did: an install was observed carrying 43 packages at alpha-269, framework/authorization at
 * alpha-270 and migration at alpha-273. A class added between versions is then registered but
 * unloadable, or loadable but never registered — and neither failure names its cause.
 *
 * Pinning the meta to the exact release makes discovery and implementation the same commit by
 * construction. The cost is intended: the framework is versioned as one unit, so updating a
 * single vortos package on its own will now conflict rather than silently skew.
 *
 * Usage:  php tools/ci/pin-meta-requires.php 1.0.0-alpha-274 [path/to/composer.json]
 */

$version = $argv[1] ?? '';
$file = $argv[2] ?? __DIR__ . '/../../packages/Vortos/composer.json';

if ($version === '') {
    fwrite(STDERR, "usage: pin-meta-requires.php <version> [composer.json]\n");
    exit(2);
}

// Accept a tag (v1.0.0-alpha-274) or a bare version; normalise to what Composer wants.
$version = ltrim($version, 'v');

if (!preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/', $version)) {
    fwrite(STDERR, "refusing to pin to a version that is not a release: {$version}\n");
    exit(2);
}

$raw = file_get_contents($file);

if ($raw === false) {
    fwrite(STDERR, "cannot read {$file}\n");
    exit(1);
}

/** @var array<string, mixed> $manifest */
$manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

if (!isset($manifest['require']) || !is_array($manifest['require'])) {
    fwrite(STDERR, "no require block in {$file}\n");
    exit(1);
}

$pinned = 0;

foreach ($manifest['require'] as $package => $constraint) {
    // Siblings only. php, ext-*, and third-party constraints are left exactly as they are.
    if (!str_starts_with((string) $package, 'vortos/')) {
        continue;
    }

    $manifest['require'][$package] = $version;
    $pinned++;
}

if ($pinned === 0) {
    fwrite(STDERR, "no vortos/* requires found — refusing to write an unchanged manifest\n");
    exit(1);
}

$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

if (file_put_contents($file, $encoded . "\n") === false) {
    fwrite(STDERR, "cannot write {$file}\n");
    exit(1);
}

fwrite(STDERR, "pinned {$pinned} vortos/* requires to {$version}\n");
