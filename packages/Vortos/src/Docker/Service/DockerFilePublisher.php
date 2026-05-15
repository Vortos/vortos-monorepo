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
        array $options = [],
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

            $contents = (string) file_get_contents($item->getPathname());
            $contents = $this->customizeContents($relativePath, $contents, $options);

            if (is_file($target) && hash('sha256', $contents) === hash_file('sha256', $target)) {
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

                file_put_contents($target, $contents, LOCK_EX);
            }

            $copied[] = $relativePath;
        }

        return new DockerPublishResult($copied, $skipped, $backedUp);
    }

    /** @param array<string, mixed> $options */
    private function customizeContents(string $relativePath, string $contents, array $options): string
    {
        if (!in_array($relativePath, ['docker-compose.yaml', 'docker-compose.prod.yaml'], true)) {
            return $contents;
        }

        foreach (($options['services'] ?? []) as $service => $enabled) {
            if ($enabled) {
                continue;
            }

            $contents = $this->removeComposeService($contents, (string) $service);
            $contents = $this->removeComposeDependsOn($contents, (string) $service);
            $contents = $this->removeComposeVolume($contents, (string) $service . '_data');
        }

        return $contents;
    }

    private function removeComposeService(string $compose, string $service): string
    {
        return (string) preg_replace(
            '/(^|\n)  ' . preg_quote($service, '/') . ":\n.*?(?=\n  [A-Za-z0-9_-]+:|\nnetworks:|\nvolumes:|\z)/s",
            '$1',
            $compose,
        );
    }

    private function removeComposeDependsOn(string $compose, string $service): string
    {
        return (string) preg_replace('/\n      - ' . preg_quote($service, '/') . '\b/', '', $compose);
    }

    private function removeComposeVolume(string $compose, string $volume): string
    {
        return (string) preg_replace(
            '/(^|\n)  ' . preg_quote($volume, '/') . ":\n(?=\s*(?:  [A-Za-z0-9_-]+:|\z))/",
            '$1',
            $compose,
        );
    }
}
