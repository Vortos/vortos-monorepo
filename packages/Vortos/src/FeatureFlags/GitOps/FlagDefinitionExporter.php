<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\GitOps;

use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Exports flag/segment definitions to a declarative JSON file for GitOps (Block 17).
 *
 * The export format is deterministic: sorted by name, stable rendering, so identical
 * flag config always produces byte-identical output. This makes `--check` an exact drift
 * guard in CI (same principle as `vortos:iac:export --check`).
 *
 * Security: SDK key secrets are NEVER exported. Only flag definitions + mutable state.
 */
final class FlagDefinitionExporter
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
    ) {}

    /**
     * Export all flags to a deterministic JSON array.
     *
     * @return array{flags: list<array<string,mixed>>, exported_at: string, version: string}
     */
    public function export(): array
    {
        $flags = $this->storage->findAll();

        usort($flags, fn(FeatureFlag $a, FeatureFlag $b) => strcmp($a->name, $b->name));

        $exported = [];
        foreach ($flags as $flag) {
            $exported[] = $this->serializeFlag($flag);
        }

        $version = 'v1:' . substr(
            hash('xxh3', json_encode($exported, JSON_THROW_ON_ERROR)),
            0,
            16,
        );

        return [
            'flags'       => $exported,
            'exported_at' => date(\DateTimeInterface::ATOM),
            'version'     => $version,
        ];
    }

    /**
     * Render the export as a stable JSON string (pretty-printed, sorted keys).
     */
    public function render(): string
    {
        $data = $this->export();

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** @return array<string,mixed> */
    private function serializeFlag(FeatureFlag $flag): array
    {
        $data = $flag->toArray();

        // Remove fields that are runtime-only (timestamps, storage IDs)
        unset($data['created_at'], $data['updated_at']);

        return $data;
    }
}
