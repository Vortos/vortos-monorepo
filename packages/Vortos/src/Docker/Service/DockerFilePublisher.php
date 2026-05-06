<?php

declare(strict_types=1);

namespace Vortos\Docker\Service;

final class DockerFilePublisher
{
    public function __construct(private readonly string $stubRoot) {}

    /** @return string[] */
    public function runtimes(): array
    {
        $runtimes = [];

        foreach (glob($this->stubRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $runtimes[] = basename($dir);
        }

        sort($runtimes);

        return $runtimes;
    }

    public function publish(
        string $runtime,
        string $projectRoot,
        bool $dryRun = false,
        bool $backup = true,
        bool $overwrite = true,
    ): DockerPublishResult {
        $source = realpath($this->stubRoot . DIRECTORY_SEPARATOR . $runtime);

        if ($source === false || !is_dir($source)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Docker runtime "%s". Valid runtimes: %s',
                $runtime,
                implode(', ', $this->runtimes()),
            ));
        }

        $copied = [];
        $skipped = [];
        $backedUp = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        ) as $item) {
            $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
            $target = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if ($item->isDir()) {
                if (!$dryRun && !is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }

            if (is_file($target) && hash_file('sha256', $item->getPathname()) === hash_file('sha256', $target)) {
                $skipped[] = $relativePath;
                continue;
            }

            if (is_file($target) && !$overwrite) {
                $skipped[] = $relativePath;
                continue;
            }

            if (!$dryRun) {
                if (is_file($target) && $backup) {
                    $backupPath = $target . '.bak.' . date('YmdHis');
                    copy($target, $backupPath);
                    $backedUp[] = str_replace('\\', '/', substr($backupPath, strlen($projectRoot) + 1));
                }

                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }

                copy($item->getPathname(), $target);
            }

            $copied[] = $relativePath;
        }

        return new DockerPublishResult($copied, $skipped, $backedUp);
    }
}
