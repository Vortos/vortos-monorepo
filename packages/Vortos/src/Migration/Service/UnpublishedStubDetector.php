<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

/**
 * Detects installed vortos/* migration stubs that have not yet been published into the app's
 * Doctrine migration set. This is the single source of truth for "would `vortos:migrate:publish`
 * emit anything?" — shared by {@see \Vortos\Migration\Command\MigratePublishCommand} (which decides
 * what to write) and by the deploy preflight gate (which fails-closed when anything is pending).
 *
 * It reproduces exactly the publisher's stub-gathering and manifest-matching rules:
 *   - schema-provider (`.php`) stubs discovered via {@see ModuleSchemaProviderScanner};
 *   - raw-SQL (`.sql`) stubs discovered via {@see ModuleStubScanner}, excluding any that is the
 *     `.sql` counterpart of a `.php` provider (the provider wins);
 *   - a stub is "published" when the manifest has an entry keyed by its relative path, honouring the
 *     legacy `.sql`→`.php` key migration for providers.
 *
 * Detection performs no writes and never throws for a normal (missing / well-formed) manifest.
 */
final class UnpublishedStubDetector
{
    private const MANIFEST_FILE = 'migrations/.vortos-published.json';

    public function __construct(
        private readonly ModuleStubScanner $scanner,
        private readonly string $projectDir,
        private readonly ?ModuleSchemaProviderScanner $schemaScanner = null,
    ) {
    }

    public function detect(): UnpublishedStubReport
    {
        $manifest = $this->loadManifest();
        $unpublished = [];

        foreach ($this->sources() as $stub) {
            if ($this->manifestKeyFor($stub, $manifest) === null) {
                $unpublished[] = [
                    'module'   => $stub['module'],
                    'filename' => $stub['filename'],
                    'relative' => $stub['relative'],
                ];
            }
        }

        return new UnpublishedStubReport($unpublished);
    }

    /**
     * The full, ordered set of migration sources (providers first, then non-counterpart SQL stubs),
     * each carrying whether it is a schema provider. Ordering matches the publisher (by filename) so
     * detection and publishing agree byte-for-byte.
     *
     * @return list<array{module: string, filename: string, path: string, relative: string, is_provider: bool}>
     */
    public function sources(): array
    {
        $sources = [];
        $providerSqlCounterparts = [];

        foreach ($this->schemaScanner?->scan() ?? [] as $schemaProvider) {
            $relative = $schemaProvider['relative'];
            $providerSqlCounterparts[$this->replaceExtension($relative, 'sql')] = true;

            $sources[] = [
                'module'      => $schemaProvider['module'],
                'filename'    => $schemaProvider['filename'],
                'path'        => $schemaProvider['path'],
                'relative'    => $relative,
                'is_provider' => true,
            ];
        }

        foreach ($this->scanner->scan() as $sqlStub) {
            if (isset($providerSqlCounterparts[$sqlStub['relative']])) {
                continue;
            }

            $sources[] = [
                'module'      => $sqlStub['module'],
                'filename'    => $sqlStub['filename'],
                'path'        => $sqlStub['path'],
                'relative'    => $sqlStub['relative'],
                'is_provider' => false,
            ];
        }

        usort($sources, static fn (array $a, array $b): int => strcmp($a['filename'], $b['filename']));

        return $sources;
    }

    /**
     * The manifest key under which a stub is recorded as published, or null if unpublished. A schema
     * provider may still be recorded under its legacy `.sql` key (pre-`.php` publishes).
     *
     * @param array{relative: string, is_provider: bool} $stub
     * @param array<string, mixed> $manifest
     */
    public function manifestKeyFor(array $stub, array $manifest): ?string
    {
        if (isset($manifest[$stub['relative']])) {
            return $stub['relative'];
        }

        if (!$stub['is_provider']) {
            return null;
        }

        $legacySqlKey = $this->replaceExtension($stub['relative'], 'sql');

        return isset($manifest[$legacySqlKey]) ? $legacySqlKey : null;
    }

    /** @return array<string, mixed> */
    public function loadManifest(): array
    {
        $path = $this->projectDir . '/' . self::MANIFEST_FILE;

        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return \is_array($data) && isset($data['published']) && \is_array($data['published'])
            ? $data['published']
            : [];
    }

    private function replaceExtension(string $path, string $extension): string
    {
        return preg_replace('/\.[^.\/]+$/', '.' . $extension, $path) ?? $path;
    }
}
