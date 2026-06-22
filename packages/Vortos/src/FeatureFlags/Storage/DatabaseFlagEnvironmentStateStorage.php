<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Doctrine\DBAL\Connection;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Prerequisite;
use Vortos\FeatureFlags\RolloutSchedule;

/**
 * DBAL-backed per-environment flag state (Block 10).
 *
 * Reads are always scoped to a single environment — one row per (flag_id, environment).
 * The `findAllForEnv()` bulk-load is the critical hot path: it executes exactly one
 * query for the entire flag set, keyed by `flag_id` so the resolver can join with
 * definitions in O(n) in PHP.
 */
final class DatabaseFlagEnvironmentStateStorage implements FlagEnvironmentStateStorageInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function findForFlag(string $flagId, string $environment): ?FlagEnvironmentState
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('flag_id = :flagId AND environment = :env')
            ->setParameter('flagId', $flagId)
            ->setParameter('env', $environment)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findAllForEnv(string $environment): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('environment = :env')
            ->setParameter('env', $environment)
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $state = $this->hydrate($row);
            $result[$state->flagId] = $state;
        }

        return $result;
    }

    public function save(FlagEnvironmentState $state): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (flag_id, environment, enabled, rules, variants, variant_rules, schedule, payload, required_scope, prerequisites, updated_at)
             VALUES
                 (:flag_id, :environment, :enabled, :rules, :variants, :variant_rules, :schedule, :payload, :required_scope, :prerequisites, :updated_at)
             ON CONFLICT (flag_id, environment) DO UPDATE SET
                 enabled       = EXCLUDED.enabled,
                 rules         = EXCLUDED.rules,
                 variants      = EXCLUDED.variants,
                 variant_rules = EXCLUDED.variant_rules,
                 schedule      = EXCLUDED.schedule,
                 payload       = EXCLUDED.payload,
                 required_scope = EXCLUDED.required_scope,
                 prerequisites = EXCLUDED.prerequisites,
                 updated_at    = EXCLUDED.updated_at',
            $this->toRow($state),
        );
    }

    public function delete(string $flagId, string $environment): void
    {
        $this->connection->delete($this->table, [
            'flag_id'     => $flagId,
            'environment' => $environment,
        ]);
    }

    private function hydrate(array $row): FlagEnvironmentState
    {
        $rules = array_map(
            fn(array $r) => FlagRule::fromArray($r),
            json_decode((string) $row['rules'], true, 512) ?? [],
        );
        $variants     = $row['variants'] !== null
            ? json_decode((string) $row['variants'], true, 512)
            : null;
        $variantRules = isset($row['variant_rules']) && $row['variant_rules'] !== null
            ? array_map(
                fn(array $rs) => array_map(fn(array $r) => FlagRule::fromArray($r), $rs),
                json_decode((string) $row['variant_rules'], true, 512, JSON_THROW_ON_ERROR) ?? [],
            )
            : null;
        $schedule      = isset($row['schedule']) && $row['schedule'] !== null
            ? RolloutSchedule::fromArray(json_decode((string) $row['schedule'], true, 512, JSON_THROW_ON_ERROR) ?? [])
            : null;
        $payload       = isset($row['payload']) && $row['payload'] !== null
            ? json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR)
            : null;
        $prerequisites = isset($row['prerequisites']) && $row['prerequisites'] !== null
            ? array_map(
                fn(array $p) => Prerequisite::fromArray($p),
                json_decode((string) $row['prerequisites'], true, 512, JSON_THROW_ON_ERROR) ?? [],
            )
            : [];

        return new FlagEnvironmentState(
            flagId:        (string) $row['flag_id'],
            environment:   (string) $row['environment'],
            enabled:       (bool) $row['enabled'],
            rules:         $rules,
            variants:      $variants,
            variantRules:  $variantRules,
            schedule:      $schedule,
            payload:       $payload,
            requiredScope: $row['required_scope'] ?? null,
            prerequisites: $prerequisites,
            updatedAt:     new \DateTimeImmutable($row['updated_at']),
        );
    }

    private function toRow(FlagEnvironmentState $state): array
    {
        return [
            'flag_id'       => $state->flagId,
            'environment'   => $state->environment,
            'enabled'       => $state->enabled ? 1 : 0,
            'rules'         => json_encode(
                array_map(fn(FlagRule $r) => $r->toArray(), $state->rules),
                JSON_THROW_ON_ERROR,
            ),
            'variants'      => $state->variants !== null
                ? json_encode($state->variants, JSON_THROW_ON_ERROR)
                : null,
            'variant_rules' => $state->variantRules !== null
                ? json_encode(
                    array_map(
                        fn(array $rs) => array_map(fn(FlagRule $r) => $r->toArray(), $rs),
                        $state->variantRules,
                    ),
                    JSON_THROW_ON_ERROR,
                )
                : null,
            'schedule'      => $state->schedule !== null
                ? json_encode($state->schedule->toArray(), JSON_THROW_ON_ERROR)
                : null,
            'payload'       => $state->payload !== null
                ? json_encode($state->payload, JSON_THROW_ON_ERROR)
                : null,
            'required_scope' => $state->requiredScope,
            'prerequisites'  => $state->prerequisites !== []
                ? json_encode(
                    array_map(fn(Prerequisite $p) => $p->toArray(), $state->prerequisites),
                    JSON_THROW_ON_ERROR,
                )
                : null,
            'updated_at'    => $state->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
