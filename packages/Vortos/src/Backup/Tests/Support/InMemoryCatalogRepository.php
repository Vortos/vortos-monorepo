<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use Vortos\Backup\Catalog\BackupAlreadyExistsException;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Catalog\BackupCatalogRepositoryInterface;
use Vortos\Backup\Catalog\RetentionCatalogInterface;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;

/** @internal in-memory catalog for runner/retention tests */
final class InMemoryCatalogRepository implements
    BackupCatalogRepositoryInterface,
    BackupCatalogReadModelInterface,
    RetentionCatalogInterface
{
    /** @var array<string, BackupArtifact> */
    public array $rows = [];

    public function record(BackupArtifact $artifact): void
    {
        if (isset($this->rows[$artifact->id->value()])) {
            throw BackupAlreadyExistsException::forId($artifact->id->value());
        }
        $this->rows[$artifact->id->value()] = $artifact;
    }

    public function forget(string $backupId): void
    {
        unset($this->rows[$backupId]);
    }

    public function byId(string $backupId): ?BackupArtifact
    {
        return $this->rows[$backupId] ?? null;
    }

    public function list(DatabaseEngine $engine, string $environment, ?BackupKind $kind = null): array
    {
        $matches = array_filter(
            $this->rows,
            static fn (BackupArtifact $a): bool => $a->engine === $engine
                && $a->environment === $environment
                && ($kind === null || $a->kind === $kind),
        );
        usort($matches, static fn (BackupArtifact $a, BackupArtifact $b): int => $b->createdAt <=> $a->createdAt);

        return array_values($matches);
    }

    public function latest(DatabaseEngine $engine, string $environment): ?BackupArtifact
    {
        return $this->list($engine, $environment)[0] ?? null;
    }

    /** @param non-empty-list<BackupKind> $kinds */
    public function latestOfKind(DatabaseEngine $engine, string $environment, array $kinds): ?BackupArtifact
    {
        foreach ($this->list($engine, $environment) as $artifact) {
            if (\in_array($artifact->kind, $kinds, true)) {
                return $artifact;
            }
        }

        return null;
    }

    public function listRestorePoints(DatabaseEngine $engine, string $environment): array
    {
        return array_values(array_filter(
            $this->list($engine, $environment),
            static fn (BackupArtifact $a): bool => $a->isRestorePoint(),
        ));
    }

    public function listWalOlderThan(
        DatabaseEngine $engine,
        string $environment,
        \DateTimeImmutable $before,
    ): array {
        return array_values(array_filter(
            $this->list($engine, $environment, BackupKind::WalSegment),
            static fn (BackupArtifact $a): bool => $a->createdAt < $before,
        ));
    }

    public function countWalFrom(
        DatabaseEngine $engine,
        string $environment,
        ?\DateTimeImmutable $from,
    ): int {
        return count(array_filter(
            $this->list($engine, $environment, BackupKind::WalSegment),
            static fn (BackupArtifact $a): bool => $from === null || $a->createdAt >= $from,
        ));
    }
}
