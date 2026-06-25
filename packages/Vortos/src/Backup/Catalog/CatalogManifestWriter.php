<?php

declare(strict_types=1);

namespace Vortos\Backup\Catalog;

use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Port\BackupStoreInterface;

/**
 * D9 self-recovery: writes a catalog-manifest.json alongside artifacts in the object
 * store so a bare object store + the off-host KEK is sufficient to enumerate and
 * restore (solves the catalog-lives-in-Postgres chicken/egg for total-host-loss DR).
 */
final class CatalogManifestWriter
{
    public function __construct(
        private readonly BackupCatalogReadModelInterface $readModel,
    ) {}

    public function write(
        BackupStoreInterface $store,
        string $keyPrefix,
        DatabaseEngine $engine,
        string $environment,
    ): void {
        $artifacts = $this->readModel->list($engine, $environment);
        $manifest = [];
        foreach ($artifacts as $artifact) {
            $manifest[] = $artifact->toArray();
        }

        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $key = sprintf('%s/%s/%s/catalog-manifest.json', trim($keyPrefix, '/'), $environment, $engine->value);

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open temp stream for manifest.');
        }
        fwrite($stream, $json);
        rewind($stream);

        $backupStream = new \Vortos\Backup\Port\BackupStream(
            $stream,
            $engine,
            \Vortos\Backup\Domain\BackupKind::LogicalFull,
            \Vortos\Backup\Domain\CompressionCodec::None,
            \Vortos\Backup\Domain\SourceRef::none(),
        );

        $store->store($backupStream, $key);
    }
}
