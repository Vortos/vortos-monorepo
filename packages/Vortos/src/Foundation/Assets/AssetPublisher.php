<?php

declare(strict_types=1);

namespace Vortos\Foundation\Assets;

final class AssetPublisher
{
    public function publish(string $vendorDir, string $publicDir, bool $symlink = false): array
    {
        $installedFile = $vendorDir . '/composer/installed.json';
        if (!is_file($installedFile)) {
            return [];
        }

        $installed = json_decode(
            (string) file_get_contents($installedFile),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $packages = $installed['packages'] ?? $installed;
        $results  = [];

        foreach ($packages as $package) {
            $assetDir = $package['extra']['vortos']['public-dir'] ?? null;
            if ($assetDir === null) {
                continue;
            }

            $name      = (string) $package['name'];
            $sourceDir = $vendorDir . '/' . $name . '/' . ltrim((string) $assetDir, '/');

            if (!is_dir($sourceDir)) {
                continue;
            }

            $targetDir = rtrim($publicDir, '/') . '/bundles/' . $this->bundleName($name);
            $results[] = $this->publishOne($name, $sourceDir, $targetDir, $symlink);
        }

        return $results;
    }

    private function bundleName(string $packageName): string
    {
        // vortos/vortos-feature-flags-admin → feature-flags-admin
        $short = explode('/', $packageName)[1] ?? $packageName;

        return preg_replace('/^vortos-/', '', $short) ?? $short;
    }

    private function publishOne(
        string $name,
        string $source,
        string $target,
        bool $symlink,
    ): PublishResult {
        try {
            $parentDir = dirname($target);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }

            if ($symlink) {
                if (is_link($target) || is_dir($target)) {
                    $this->remove($target);
                }
                symlink($source, $target);

                return new PublishResult($name, $target, 'symlinked');
            }

            $this->copyDirectory($source, $target);

            return new PublishResult($name, $target, 'copied');
        } catch (\Throwable $e) {
            return new PublishResult($name, $target, 'failed', $e->getMessage());
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $dest = $target . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
            } else {
                copy($item->getPathname(), $dest);
            }
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($path);
        }
    }
}
