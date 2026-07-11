<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

final class FeatureFlag
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly bool $enabled,
        /** @var FlagRule[] */
        public readonly array $rules,
        /** @var array<string,int>|null variant name → percentage */
        public readonly ?array $variants,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly FlagValueType $valueType = FlagValueType::Bool,
        /** Guaranteed safe fallback returned when the flag is off / unmatched / un-evaluatable. */
        public readonly ?FlagValue $defaultValue = null,
        /** Optional JSON remote-config blob delivered (as payloads[name]) when the flag is on. */
        public readonly ?array $payload = null,
        /** Which context dimension the rollout buckets on: userId | tenantId | accountId | deviceId | sessionId. */
        public readonly string $bucketBy = self::BUCKET_BY_USER,
        public readonly FlagKind $kind = FlagKind::Release,
        /** @var Prerequisite[] other flags that must hold for this flag to be eligible */
        public readonly array $prerequisites = [],
        /** @var array<string,FlagRule[]>|null per-variant forced-assignment rules (override weights) */
        public readonly ?array $variantRules = null,
        /** Optional time-based scheduled window + gradual ramp, resolved at eval time. */
        public readonly ?RolloutSchedule $schedule = null,
        /**
         * Optional authorization scope (resource.action.scope) that the subject must
         * satisfy for this flag to be ON (Block 9). Deny-only gate: it can turn a flag
         * off, never on. Intended for `permission`-kind flags.
         */
        public readonly ?string $requiredScope = null,
        /**
         * The environment this flag instance was resolved for (Block 10). Populated by
         * FeatureFlag::compose(); defaults to 'production' for back-compat (Phase A/B
         * flags resolved before env state existed). Informational: the evaluator and
         * registry do not branch on this field — it is only used for cache keying (via
         * FlagScopeContext) and the version hash.
         */
        public readonly string $environment = FlagScopeContext::ENV_PRODUCTION,
        /**
         * The project this flag belongs to (Block 11). Defaults to 'default' for back-compat.
         */
        public readonly string $projectId = ProjectContext::DEFAULT_PROJECT,
        /**
         * Lifecycle stage (Block 12). Draft = not yet live; Active = normal eval;
         * Archived = soft-deleted, kept for audit. Defaults to Active for back-compat.
         */
        public readonly FlagLifecycleState $lifecycle = FlagLifecycleState::Active,
        /**
         * Owner identifier — team slug, email, or squad name (Block 12).
         */
        public readonly ?string $owner = null,
        /**
         * UTC expiry date (Block 12). When set and past, stale-detection surfaces the flag.
         * The evaluator does NOT auto-disable on expiry — a human or workflow must archive it.
         */
        public readonly ?\DateTimeImmutable $expiresAt = null,
        /**
         * Layer ID (Block 30). When set, this flag's rollout bucket is drawn from the layer's
         * partition rather than an independent salt, guaranteeing mutual exclusion with other
         * experiments in the same layer. Null = non-layered (standard independent rollout).
         */
        public readonly ?string $layerId = null,
    ) {}

    public const BUCKET_BY_USER    = 'userId';
    public const BUCKET_BY_TENANT  = 'tenantId';
    public const BUCKET_BY_ACCOUNT = 'accountId';
    public const BUCKET_BY_DEVICE  = 'deviceId';
    public const BUCKET_BY_SESSION = 'sessionId';

    public const BUCKET_KEYS = [
        self::BUCKET_BY_USER, self::BUCKET_BY_TENANT, self::BUCKET_BY_ACCOUNT,
        self::BUCKET_BY_DEVICE, self::BUCKET_BY_SESSION,
    ];

    /** The safe default, always typed — synthesised from the value type when not explicitly set. */
    public function defaultValue(): FlagValue
    {
        return $this->defaultValue ?? FlagValue::zero($this->valueType);
    }

    /**
     * Compose a FeatureFlag from an environment-invariant definition and its per-environment
     * mutable state (Block 10). The definition supplies `id / name / description /
     * valueType / kind / bucketBy / defaultValue / createdAt`; the state supplies
     * `enabled / rules / variants / variantRules / schedule / payload / requiredScope /
     * prerequisites / updatedAt / environment`.
     *
     * This is the single factory that turns the split storage model back into the single
     * FeatureFlag projection the evaluator and registry consume unchanged.
     */
    public static function compose(self $definition, FlagEnvironmentState $state): self
    {
        return new self(
            id:           $definition->id,
            name:         $definition->name,
            description:  $definition->description,
            enabled:      $state->enabled,
            rules:        $state->rules,
            variants:     $state->variants,
            createdAt:    $definition->createdAt,
            updatedAt:    $state->updatedAt,
            valueType:    $definition->valueType,
            defaultValue: $definition->defaultValue,
            payload:      $state->payload,
            bucketBy:     $definition->bucketBy,
            kind:         $definition->kind,
            prerequisites: $state->prerequisites,
            variantRules: $state->variantRules,
            schedule:     $state->schedule,
            requiredScope: $state->requiredScope,
            environment:  $state->environment,
            projectId:    $definition->projectId,
            lifecycle:    $definition->lifecycle,
            owner:        $definition->owner,
            expiresAt:    $definition->expiresAt,
            layerId:      $definition->layerId,
        );
    }

    /**
     * Named-argument clone helper — the single place all with* methods delegate to.
     * Eliminates the positional-argument bug where a short `new self(...)` call silently
     * drops trailing fields (the original `withPayload()` dropped bucketBy…requiredScope).
     * Every with* method is now a one-liner that passes only what changed.
     */
    private function withClone(
        ?bool $enabled = null,
        ?array $rules = null,
        bool $variantsSet = false,
        ?array $variants = null,
        bool $payloadSet = false,
        ?array $payload = null,
        bool $scheduleSet = false,
        ?RolloutSchedule $schedule = null,
        bool $variantRulesSet = false,
        ?array $variantRules = null,
        bool $requiredScopeSet = false,
        ?string $requiredScope = null,
        ?string $environment = null,
        ?string $projectId = null,
        ?FlagLifecycleState $lifecycle = null,
        bool $ownerSet = false,
        ?string $owner = null,
        bool $expiresAtSet = false,
        ?\DateTimeImmutable $expiresAt = null,
        bool $layerIdSet = false,
        ?string $layerId = null,
        ?string $description = null,
        ?string $bucketBy = null,
        ?FlagKind $kind = null,
        ?array $prerequisites = null,
        bool $defaultValueSet = false,
        ?FlagValue $defaultValue = null,
    ): self {
        return new self(
            id:           $this->id,
            name:         $this->name,
            description:  $description ?? $this->description,
            enabled:      $enabled ?? $this->enabled,
            rules:        $rules ?? $this->rules,
            variants:     $variantsSet ? $variants : $this->variants,
            createdAt:    $this->createdAt,
            updatedAt:    new \DateTimeImmutable(),
            valueType:    $this->valueType,
            defaultValue: $defaultValueSet ? $defaultValue : $this->defaultValue,
            payload:      $payloadSet ? $payload : $this->payload,
            bucketBy:     $bucketBy ?? $this->bucketBy,
            kind:         $kind ?? $this->kind,
            prerequisites: $prerequisites ?? $this->prerequisites,
            variantRules: $variantRulesSet ? $variantRules : $this->variantRules,
            schedule:     $scheduleSet ? $schedule : $this->schedule,
            requiredScope: $requiredScopeSet ? $requiredScope : $this->requiredScope,
            environment:  $environment ?? $this->environment,
            projectId:    $projectId ?? $this->projectId,
            lifecycle:    $lifecycle ?? $this->lifecycle,
            owner:        $ownerSet ? $owner : $this->owner,
            expiresAt:    $expiresAtSet ? $expiresAt : $this->expiresAt,
            layerId:      $layerIdSet ? $layerId : $this->layerId,
        );
    }

    public function withDescription(string $description): self
    {
        return $this->withClone(description: $description);
    }

    public function withKind(FlagKind $kind): self
    {
        return $this->withClone(kind: $kind);
    }

    public function withBucketBy(string $bucketBy): self
    {
        return $this->withClone(bucketBy: $bucketBy);
    }

    /** @param Prerequisite[] $prerequisites */
    public function withPrerequisites(array $prerequisites): self
    {
        return $this->withClone(prerequisites: $prerequisites);
    }

    public function withRequiredScope(?string $requiredScope): self
    {
        return $this->withClone(requiredScopeSet: true, requiredScope: $requiredScope);
    }

    /** @param array<string,FlagRule[]>|null $variantRules */
    public function withVariantRules(?array $variantRules): self
    {
        return $this->withClone(variantRulesSet: true, variantRules: $variantRules);
    }

    public function withDefaultValue(?FlagValue $defaultValue): self
    {
        return $this->withClone(defaultValueSet: true, defaultValue: $defaultValue);
    }

    public function withEnabled(bool $enabled): self
    {
        return $this->withClone(enabled: $enabled);
    }

    /** @param FlagRule[] $rules */
    public function withRules(array $rules): self
    {
        return $this->withClone(rules: $rules);
    }

    /** @param array<array-key,mixed>|null $payload */
    public function withPayload(?array $payload): self
    {
        return $this->withClone(payloadSet: true, payload: $payload);
    }

    /** @param array<string,int>|null $variants variant name → weight */
    public function withVariants(?array $variants): self
    {
        return $this->withClone(variantsSet: true, variants: $variants);
    }

    public function withSchedule(?RolloutSchedule $schedule): self
    {
        return $this->withClone(scheduleSet: true, schedule: $schedule);
    }

    public function withEnvironment(string $environment): self
    {
        return $this->withClone(environment: $environment);
    }

    public function withProject(string $projectId): self
    {
        return $this->withClone(projectId: $projectId);
    }

    public function withLifecycle(FlagLifecycleState $lifecycle): self
    {
        return $this->withClone(lifecycle: $lifecycle);
    }

    public function withOwner(?string $owner): self
    {
        return $this->withClone(ownerSet: true, owner: $owner);
    }

    public function withExpiry(?\DateTimeImmutable $expiresAt): self
    {
        return $this->withClone(expiresAtSet: true, expiresAt: $expiresAt);
    }

    public function withLayer(?string $layerId): self
    {
        return $this->withClone(layerIdSet: true, layerId: $layerId);
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function isLive(): bool
    {
        return $this->lifecycle->isLive();
    }

    public function isVariant(): bool
    {
        return $this->variants !== null;
    }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'description'   => $this->description,
            'enabled'       => $this->enabled,
            'rules'         => array_map(fn(FlagRule $r) => $r->toArray(), $this->rules),
            'variants'      => $this->variants,
            'value_type'    => $this->valueType->value,
            'default_value' => $this->defaultValue()->encode(),
            'payload'       => $this->payload,
            'bucket_by'     => $this->bucketBy,
            'kind'          => $this->kind->value,
            'prerequisites' => array_map(fn(Prerequisite $p) => $p->toArray(), $this->prerequisites),
            'variant_rules' => $this->variantRules !== null
                ? array_map(
                    fn(array $rules) => array_map(fn(FlagRule $r) => $r->toArray(), $rules),
                    $this->variantRules,
                )
                : null,
            'schedule'      => $this->schedule?->toArray(),
            'required_scope' => $this->requiredScope,
            'environment'   => $this->environment,
            'project_id'    => $this->projectId,
            'lifecycle'     => $this->lifecycle->value,
            'owner'         => $this->owner,
            'expires_at'    => $this->expiresAt?->format(\DateTimeInterface::ATOM),
            'created_at'    => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'    => $this->updatedAt->format(\DateTimeInterface::ATOM),
            'layer_id'      => $this->layerId,
        ];
    }

    public static function fromArray(array $data): self
    {
        // Back-compat: legacy rows have no value_type → boolean flag with a `false` default.
        $valueType = isset($data['value_type'])
            ? FlagValueType::from($data['value_type'])
            : FlagValueType::Bool;

        return new self(
            id:           $data['id'],
            name:         $data['name'],
            description:  $data['description'],
            enabled:      (bool) $data['enabled'],
            rules:        array_map(fn(array $r) => FlagRule::fromArray($r), $data['rules'] ?? []),
            variants:     $data['variants'] ?? null,
            createdAt:    new \DateTimeImmutable($data['created_at']),
            updatedAt:    new \DateTimeImmutable($data['updated_at']),
            valueType:    $valueType,
            defaultValue: FlagValue::decode($valueType, $data['default_value'] ?? null),
            payload:      $data['payload'] ?? null,
            bucketBy:     $data['bucket_by'] ?? self::BUCKET_BY_USER,
            kind:         isset($data['kind']) ? FlagKind::from($data['kind']) : FlagKind::Release,
            prerequisites: array_map(
                fn(array $p) => Prerequisite::fromArray($p),
                $data['prerequisites'] ?? [],
            ),
            variantRules: isset($data['variant_rules']) && $data['variant_rules'] !== null
                ? array_map(
                    fn(array $rules) => array_map(fn(array $r) => FlagRule::fromArray($r), $rules),
                    $data['variant_rules'],
                )
                : null,
            schedule: isset($data['schedule']) && $data['schedule'] !== null
                ? RolloutSchedule::fromArray($data['schedule'])
                : null,
            requiredScope: $data['required_scope'] ?? null,
            environment:   $data['environment'] ?? FlagScopeContext::ENV_PRODUCTION,
            projectId:     $data['project_id'] ?? ProjectContext::DEFAULT_PROJECT,
            lifecycle:     isset($data['lifecycle']) ? FlagLifecycleState::from($data['lifecycle']) : FlagLifecycleState::Active,
            owner:         $data['owner'] ?? null,
            expiresAt:     isset($data['expires_at']) && $data['expires_at'] !== null
                ? new \DateTimeImmutable($data['expires_at'])
                : null,
            layerId:       $data['layer_id'] ?? null,
        );
    }
}
