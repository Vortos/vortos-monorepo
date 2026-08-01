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
        $diverged = [];

        /** @var list<string> $only */
        $only = array_values(array_filter((array) ($options['only'] ?? []), 'is_string'));
        $overwriteDiverged = (bool) ($options['overwriteDiverged'] ?? false);

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

            if ($only !== [] && !$this->matchesAny($relativePath, $only)) {
                $skipped[] = $relativePath;
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

            // A file that already exists and differs is one somebody has edited — or one the stub
            // has moved on from. The publisher cannot tell those apart, and the failure mode is
            // asymmetric: re-applying a stub update costs a re-run, while reverting a hand-tuned
            // production Dockerfile or a worker's supervisord config is a silent regression that
            // ships. So it is reported, and skipped unless explicitly allowed.
            if (is_file($target)) {
                $diverged[$relativePath] = [
                    'current' => $this->lineCount((string) file_get_contents($target)),
                    'new'     => $this->lineCount($contents),
                ];

                if (!$overwriteDiverged) {
                    $skipped[] = $relativePath;
                    continue;
                }
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

        return new DockerPublishResult($copied, $skipped, $backedUp, $diverged);
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
            return $this->applyCorsOriginPattern(
                $this->applyCaddyFeatures($contents, $options),
                $options,
            );
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
     * Whether a stub path was asked for.
     *
     * Matches a full relative path or any directory prefix of it, so `--only=docker/frankenphp`
     * takes that whole directory and `--only=docker/frankenphp/Caddyfile` takes the one file.
     * Deliberately not a glob: the point of the filter is to be certain what it selected, and a
     * pattern language invites a `*` that quietly matches more than the operator meant.
     *
     * @param list<string> $only
     */
    private function matchesAny(string $relativePath, array $only): bool
    {
        foreach ($only as $candidate) {
            $candidate = trim(str_replace('\\', '/', $candidate), '/');

            if ($candidate === '' ) {
                continue;
            }

            if ($relativePath === $candidate || str_starts_with($relativePath, $candidate . '/')) {
                return true;
            }
        }

        return false;
    }

    private function lineCount(string $contents): int
    {
        return substr_count($contents, "\n") + ($contents !== '' && !str_ends_with($contents, "\n") ? 1 : 0);
    }

    /**
     * Writes the app's CORS allowlist into the Caddyfile as an anchored alternation.
     *
     * The edge answers requests it rejects before PHP ever runs, so it has to know which origins
     * may read those responses. Deriving the pattern here — from the same `origins` list
     * `config/security.php` declares — keeps one source of truth; the alternative is an env var
     * an operator has to remember to keep in step, which is a copy that silently drifts and then
     * fails closed on exactly the responses nobody is watching.
     *
     * An empty allowlist yields `^$`, which matches no Origin header, so the CORS branch never
     * fires and rejected requests keep Caddy's default handling. That is the correct failure
     * direction: an app that declares no origins gets no origin echoed.
     *
     * `*` and leading-wildcard entries (`*.example.com`) are translated rather than dropped,
     * because the middleware honours both and an edge that disagreed with it would be worse
     * than one that says nothing.
     *
     * @param array<string, mixed> $options
     */
    private function applyCorsOriginPattern(string $contents, array $options): string
    {
        /** @var list<string> $origins */
        $origins = array_values(array_filter(
            (array) ($options['corsOrigins'] ?? []),
            static fn (mixed $o): bool => is_string($o) && $o !== '',
        ));

        $alternatives = array_map(
            static function (string $origin): string {
                if ($origin === '*') {
                    return '.*';
                }

                if (str_starts_with($origin, '*.')) {
                    return '[a-z0-9-]+\.' . preg_quote(substr($origin, 2), '/');
                }

                return preg_quote($origin, '/');
            },
            $origins,
        );

        $pattern = $alternatives === []
            ? '^$'
            : '^(' . implode('|', $alternatives) . ')$';

        return str_replace('{{VORTOS_CORS_ORIGIN_PATTERN}}', $pattern, $contents);
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
