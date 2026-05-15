<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Schema\MigrationOwnership;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Schema\ModuleSchemaProviderInterface;

final class ModuleMigrationRegistry
{
    private const MANIFEST_FILE = 'migrations/.vortos-published.json';

    public function __construct(
        private readonly ModuleSchemaProviderScanner $schemaScanner,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, ModuleMigrationDescriptor> keyed by migration class
     */
    public function descriptorsByClass(): array
    {
        $manifest = $this->loadManifest();
        $descriptors = [];
        $providerKeys = [];

        foreach ($this->schemaScanner->scan() as $source) {
            $relative = $source['relative'];
            $legacySql = $this->replaceExtension($relative, 'sql');
            $entry = $manifest[$relative] ?? $manifest[$legacySql] ?? null;

            if (!is_array($entry) || !isset($entry['class']) || !is_string($entry['class'])) {
                continue;
            }

            /** @var ModuleSchemaProviderInterface $provider */
            $provider = $source['provider'];
            $ownership = $provider->ownership();
            $checksum = isset($entry['checksum']) && is_string($entry['checksum']) ? $entry['checksum'] : null;

            $descriptors[$entry['class']] = new ModuleMigrationDescriptor(
                source: isset($manifest[$relative]) ? $relative : $legacySql,
                class: $entry['class'],
                module: $provider->module(),
                filename: $source['filename'],
                ownership: $ownership,
                provider: $provider,
                checksum: $checksum,
            );

            $providerKeys[$relative] = true;
            $providerKeys[$legacySql] = true;
        }

        foreach ($manifest as $source => $entry) {
            if (isset($providerKeys[$source]) || !is_array($entry) || !isset($entry['class']) || !is_string($entry['class'])) {
                continue;
            }

            $objects = is_array($entry['objects'] ?? null) ? $entry['objects'] : [];
            $tables = array_values(array_filter($objects['tables'] ?? [], 'is_string'));
            $indexes = array_values(array_filter($objects['indexes'] ?? [], 'is_string'));
            $module = isset($entry['module']) && is_string($entry['module']) ? $entry['module'] : 'Unknown';
            $filename = isset($entry['filename']) && is_string($entry['filename']) ? $entry['filename'] : basename((string) $source);
            $checksum = isset($entry['checksum']) && is_string($entry['checksum']) ? $entry['checksum'] : null;

            $descriptors[$entry['class']] = new ModuleMigrationDescriptor(
                source: (string) $source,
                class: $entry['class'],
                module: $module,
                filename: $filename,
                ownership: new MigrationOwnership($tables, $indexes),
                provider: null,
                checksum: $checksum,
            );
        }

        ksort($descriptors);

        return $descriptors;
    }

    public function descriptorForClass(string $class): ?ModuleMigrationDescriptor
    {
        return $this->descriptorsByClass()[$class] ?? null;
    }

    /** @return array<string, mixed> */
    private function loadManifest(): array
    {
        $path = $this->projectDir . '/' . self::MANIFEST_FILE;

        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data['published'] ?? null) ? $data['published'] : [];
    }

    private function replaceExtension(string $path, string $extension): string
    {
        return preg_replace('/\.[^.\/]+$/', '.' . $extension, $path) ?? $path;
    }
}
