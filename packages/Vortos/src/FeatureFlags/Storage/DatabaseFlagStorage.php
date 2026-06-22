<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Doctrine\DBAL\Connection;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagLifecycleState;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\Prerequisite;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\RolloutSchedule;

final class DatabaseFlagStorage implements FlagStorageInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function findAll(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByName(string $name): ?FeatureFlag
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function save(FeatureFlag $flag): void
    {
        $row = $this->toRow($flag);

        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (id, name, description, enabled, rules, variants, value_type, default_value, payload, bucket_by, kind, prerequisites, variant_rules, schedule, required_scope, project_id, lifecycle, owner, expires_at, created_at, updated_at)
             VALUES
                 (:id, :name, :description, :enabled, :rules, :variants, :value_type, :default_value, :payload, :bucket_by, :kind, :prerequisites, :variant_rules, :schedule, :required_scope, :project_id, :lifecycle, :owner, :expires_at, :created_at, :updated_at)
             ON CONFLICT (name) DO UPDATE SET
                 description   = EXCLUDED.description,
                 enabled       = EXCLUDED.enabled,
                 rules         = EXCLUDED.rules,
                 variants      = EXCLUDED.variants,
                 value_type    = EXCLUDED.value_type,
                 default_value = EXCLUDED.default_value,
                 payload       = EXCLUDED.payload,
                 bucket_by     = EXCLUDED.bucket_by,
                 kind          = EXCLUDED.kind,
                 prerequisites = EXCLUDED.prerequisites,
                 variant_rules = EXCLUDED.variant_rules,
                 schedule      = EXCLUDED.schedule,
                 required_scope = EXCLUDED.required_scope,
                 project_id    = EXCLUDED.project_id,
                 lifecycle     = EXCLUDED.lifecycle,
                 owner         = EXCLUDED.owner,
                 expires_at    = EXCLUDED.expires_at,
                 updated_at    = EXCLUDED.updated_at',
            $row,
        );
    }

    public function delete(string $name): void
    {
        $this->connection->delete($this->table, ['name' => $name]);
    }

    private function hydrate(array $row): FeatureFlag
    {
        $rules    = array_map(
            fn(array $r) => FlagRule::fromArray($r),
            json_decode($row['rules'], true, 512) ?? [],
        );
        $variants = $row['variants'] !== null
            ? json_decode($row['variants'], true, 512)
            : null;

        // Back-compat: legacy rows predate these columns → boolean flag, null default/payload.
        $valueType = isset($row['value_type']) && $row['value_type'] !== null
            ? FlagValueType::from($row['value_type'])
            : FlagValueType::Bool;
        $payload   = isset($row['payload']) && $row['payload'] !== null
            ? json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR)
            : null;

        $kind          = isset($row['kind']) && $row['kind'] !== null
            ? FlagKind::from($row['kind'])
            : FlagKind::Release;
        $prerequisites = isset($row['prerequisites']) && $row['prerequisites'] !== null
            ? array_map(fn(array $p) => Prerequisite::fromArray($p), json_decode($row['prerequisites'], true, 512, JSON_THROW_ON_ERROR) ?? [])
            : [];
        $variantRules  = isset($row['variant_rules']) && $row['variant_rules'] !== null
            ? array_map(
                fn(array $rules) => array_map(fn(array $r) => FlagRule::fromArray($r), $rules),
                json_decode($row['variant_rules'], true, 512, JSON_THROW_ON_ERROR) ?? [],
            )
            : null;
        $schedule      = isset($row['schedule']) && $row['schedule'] !== null
            ? RolloutSchedule::fromArray(json_decode($row['schedule'], true, 512, JSON_THROW_ON_ERROR) ?? [])
            : null;

        return new FeatureFlag(
            id:           $row['id'],
            name:         $row['name'],
            description:  $row['description'],
            enabled:      (bool) $row['enabled'],
            rules:        $rules,
            variants:     $variants,
            createdAt:    new \DateTimeImmutable($row['created_at']),
            updatedAt:    new \DateTimeImmutable($row['updated_at']),
            valueType:    $valueType,
            defaultValue: FlagValue::decode($valueType, $row['default_value'] ?? null),
            payload:      $payload,
            bucketBy:     $row['bucket_by'] ?? FeatureFlag::BUCKET_BY_USER,
            kind:          $kind,
            prerequisites: $prerequisites,
            variantRules:  $variantRules,
            schedule:      $schedule,
            requiredScope: $row['required_scope'] ?? null,
            projectId:     $row['project_id'] ?? ProjectContext::DEFAULT_PROJECT,
            lifecycle:     isset($row['lifecycle']) && $row['lifecycle'] !== null
                ? FlagLifecycleState::from($row['lifecycle'])
                : FlagLifecycleState::Active,
            owner:         $row['owner'] ?? null,
            expiresAt:     isset($row['expires_at']) && $row['expires_at'] !== null
                ? new \DateTimeImmutable($row['expires_at'])
                : null,
        );
    }

    private function toRow(FeatureFlag $flag): array
    {
        return [
            'id'            => $flag->id,
            'name'          => $flag->name,
            'description'   => $flag->description,
            'enabled'       => $flag->enabled ? 1 : 0,
            'rules'         => json_encode(array_map(fn(FlagRule $r) => $r->toArray(), $flag->rules)),
            'variants'      => $flag->variants !== null ? json_encode($flag->variants) : null,
            'value_type'    => $flag->valueType->value,
            'default_value' => $flag->defaultValue()->encode(),
            'payload'       => $flag->payload !== null ? json_encode($flag->payload, JSON_THROW_ON_ERROR) : null,
            'bucket_by'     => $flag->bucketBy,
            'kind'          => $flag->kind->value,
            'prerequisites' => $flag->prerequisites !== []
                ? json_encode(array_map(fn(Prerequisite $p) => $p->toArray(), $flag->prerequisites), JSON_THROW_ON_ERROR)
                : null,
            'variant_rules' => $flag->variantRules !== null
                ? json_encode(array_map(
                    fn(array $rules) => array_map(fn(FlagRule $r) => $r->toArray(), $rules),
                    $flag->variantRules,
                ), JSON_THROW_ON_ERROR)
                : null,
            'schedule'      => $flag->schedule !== null ? json_encode($flag->schedule->toArray(), JSON_THROW_ON_ERROR) : null,
            'required_scope' => $flag->requiredScope,
            'project_id'    => $flag->projectId,
            'lifecycle'     => $flag->lifecycle->value,
            'owner'         => $flag->owner,
            'expires_at'    => $flag->expiresAt?->format('Y-m-d H:i:s'),
            'created_at'    => $flag->createdAt->format('Y-m-d H:i:s'),
            'updated_at'    => $flag->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
