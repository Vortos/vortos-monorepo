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

    /**
     * Caddyfile sections that are opt-in, keyed by the marker name wrapping them in the stub. Each is
     * stripped unless the matching `features` option is enabled.
     *
     * These exist because some capabilities cannot ship enabled-by-default without breaking apps that
     * do not use them: the Mercure hub refuses to start without a signing secret, so publishing it
     * unconditionally would take every existing project's next deploy down. Defaulting the secret so
     * the config always parses is not an option either — a hub signed with a value from the
     * framework's own source is worse than no hub.
     */
    private const OPTIONAL_CADDY_SECTIONS = ['mercure'];

    /** @param array<string, mixed> $options */
    private function customizeContents(string $relativePath, string $contents, array $options): string
    {
        if (str_ends_with($relativePath, 'docker/frankenphp/Caddyfile')) {
            return $this->applyCaddyFeatures($contents, $options);
        }

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

    /**
     * Strips each opt-in Caddyfile section whose feature is not enabled, leaving the markers' contents
     * out entirely rather than commenting them: a commented-out `mercure` block is one careless
     * uncomment away from a hub with no secret, and it invites hand-editing a published file — which is
     * silently reverted on the next publish.
     *
     * @param array<string, mixed> $options
     */
    private function applyCaddyFeatures(string $contents, array $options): string
    {
        $features = $options['features'] ?? [];

        foreach (self::OPTIONAL_CADDY_SECTIONS as $section) {
            if (!empty($features[$section])) {
                continue;
            }

            $contents = $this->removeCaddySection($contents, $section);
        }

        return $contents;
    }

    /**
     * Removes a `# <vortos-NAME>` … `# </vortos-NAME>` block, markers included, along with the blank
     * line it leaves behind. The marker idiom matches the supervisord generator's, so a reader who has
     * seen one recognises the other as machine-managed and not hand-editable.
     */
    private function removeCaddySection(string $contents, string $section): string
    {
        $pattern = sprintf(
            '/[ \t]*#[ \t]*<vortos-%1$s>.*?#[ \t]*<\/vortos-%1$s>[ \t]*\n/s',
            preg_quote($section, '/'),
        );

        return (string) preg_replace($pattern, '', $contents);
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
