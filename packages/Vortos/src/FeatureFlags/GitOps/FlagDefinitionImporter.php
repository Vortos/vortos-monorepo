<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\GitOps;

use Symfony\Component\Uid\Uuid;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Imports flag definitions from a declarative JSON file, routing every mutation through
 * {@see FlagWriteService} so the full audit ledger is maintained.
 *
 * Supports --dry-run mode: returns a diff without touching storage.
 */
final class FlagDefinitionImporter
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagWriteService $writeService,
    ) {}

    /**
     * @param array{flags: list<array<string,mixed>>} $data  Parsed JSON from the export file
     * @param bool $dryRun  When true, compute the diff but do not apply changes
     * @return ImportResult
     */
    public function import(array $data, bool $dryRun = false, string $actorId = 'gitops'): ImportResult
    {
        $declared = $this->parseDeclared($data);
        $effective = $this->loadEffective();

        $toCreate  = [];
        $toUpdate  = [];
        $toDelete  = [];

        $declaredNames = [];
        foreach ($declared as $flag) {
            $declaredNames[$flag->name] = true;

            if (!isset($effective[$flag->name])) {
                $toCreate[] = $flag;
            } elseif ($this->flagDiffers($effective[$flag->name], $flag)) {
                $toUpdate[] = $flag;
            }
        }

        foreach ($effective as $name => $flag) {
            if (!isset($declaredNames[$name])) {
                $toDelete[] = $flag;
            }
        }

        if (!$dryRun) {
            $this->applyChanges($toCreate, $toUpdate, $toDelete, $actorId);
        }

        return new ImportResult(
            created: array_map(fn(FeatureFlag $f) => $f->name, $toCreate),
            updated: array_map(fn(FeatureFlag $f) => $f->name, $toUpdate),
            deleted: array_map(fn(FeatureFlag $f) => $f->name, $toDelete),
            dryRun: $dryRun,
        );
    }

    /** @return list<FeatureFlag> */
    private function parseDeclared(array $data): array
    {
        if (!isset($data['flags']) || !is_array($data['flags'])) {
            throw new \InvalidArgumentException('Invalid import data: missing "flags" key');
        }

        return array_map(function (array $flagData): FeatureFlag {
            $now = new \DateTimeImmutable();
            if (!isset($flagData['created_at'])) {
                $flagData['created_at'] = $now->format(\DateTimeInterface::ATOM);
            }
            if (!isset($flagData['updated_at'])) {
                $flagData['updated_at'] = $now->format(\DateTimeInterface::ATOM);
            }
            if (!isset($flagData['id'])) {
                $flagData['id'] = (string) Uuid::v4();
            }

            return FeatureFlag::fromArray($flagData);
        }, $data['flags']);
    }

    /** @return array<string, FeatureFlag> keyed by name */
    private function loadEffective(): array
    {
        $result = [];
        foreach ($this->storage->findAll() as $flag) {
            $result[$flag->name] = $flag;
        }

        return $result;
    }

    private function flagDiffers(FeatureFlag $existing, FeatureFlag $declared): bool
    {
        $a = $existing->toArray();
        $b = $declared->toArray();

        unset($a['id'], $b['id'], $a['created_at'], $b['created_at'], $a['updated_at'], $b['updated_at']);

        return $a !== $b;
    }

    /** @param FeatureFlag[] $toCreate @param FeatureFlag[] $toUpdate @param FeatureFlag[] $toDelete */
    private function applyChanges(array $toCreate, array $toUpdate, array $toDelete, string $actorId): void
    {
        foreach ($toCreate as $flag) {
            $this->writeService->create($flag, $actorId, 'gitops import: created');
        }

        foreach ($toUpdate as $flag) {
            $this->writeService->revertTo($flag, $actorId, 'gitops import: updated from definition file');
        }

        foreach ($toDelete as $flag) {
            $this->writeService->archiveAndDelete($flag->name, $actorId, 'gitops import: removed from definition file');
        }
    }
}
