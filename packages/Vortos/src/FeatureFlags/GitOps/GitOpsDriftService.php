<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\GitOps;

use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Detects drift between a declared flag definition file and the effective runtime state.
 *
 * A drift report answers: "is someone making ad-hoc changes that aren't captured in the
 * definition file?" This is the CI guard: `vortos:flags:drift --check` returns exit code 1
 * if any field diverges.
 */
final class GitOpsDriftService
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
    ) {}

    /**
     * @param array{flags: list<array<string,mixed>>} $declared  The parsed definition file
     * @return DriftReport
     */
    public function detect(array $declared): DriftReport
    {
        $declaredFlags = $this->indexByName($declared);
        $effectiveFlags = $this->loadEffective();

        $drifts = [];

        foreach ($declaredFlags as $name => $declaredArr) {
            if (!isset($effectiveFlags[$name])) {
                $drifts[] = new DriftEntry(
                    flagName: $name,
                    type: DriftType::MissingInRuntime,
                    details: 'Flag declared in definition file but not present in runtime storage',
                );
                continue;
            }

            $effectiveArr = $effectiveFlags[$name]->toArray();

            unset(
                $declaredArr['id'], $effectiveArr['id'],
                $declaredArr['created_at'], $effectiveArr['created_at'],
                $declaredArr['updated_at'], $effectiveArr['updated_at'],
            );

            $fieldDiffs = $this->diffFields($declaredArr, $effectiveArr);
            if (count($fieldDiffs) > 0) {
                $drifts[] = new DriftEntry(
                    flagName: $name,
                    type: DriftType::FieldMismatch,
                    details: 'Drifted fields: ' . implode(', ', array_keys($fieldDiffs)),
                    fields: $fieldDiffs,
                );
            }
        }

        foreach ($effectiveFlags as $name => $_) {
            if (!isset($declaredFlags[$name])) {
                $drifts[] = new DriftEntry(
                    flagName: $name,
                    type: DriftType::UndeclaredInFile,
                    details: 'Flag exists at runtime but is not declared in definition file',
                );
            }
        }

        return new DriftReport($drifts);
    }

    /**
     * @return array<string, array<string,mixed>> keyed by flag name
     */
    private function indexByName(array $data): array
    {
        $result = [];
        foreach (($data['flags'] ?? []) as $flagData) {
            $name = $flagData['name'] ?? '';
            $result[$name] = $flagData;
        }

        return $result;
    }

    /** @return array<string, FeatureFlag> */
    private function loadEffective(): array
    {
        $result = [];
        foreach ($this->storage->findAll() as $flag) {
            $result[$flag->name] = $flag;
        }

        return $result;
    }

    /** @return array<string, array{declared: mixed, effective: mixed}> */
    private function diffFields(array $declared, array $effective): array
    {
        $diffs = [];
        $allKeys = array_unique(array_merge(array_keys($declared), array_keys($effective)));

        foreach ($allKeys as $key) {
            $dVal = $declared[$key] ?? null;
            $eVal = $effective[$key] ?? null;

            if ($dVal !== $eVal) {
                $diffs[$key] = ['declared' => $dVal, 'effective' => $eVal];
            }
        }

        return $diffs;
    }
}
