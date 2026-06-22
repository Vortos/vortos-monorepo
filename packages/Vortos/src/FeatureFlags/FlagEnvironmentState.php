<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * The per-environment mutable state of a feature flag (Block 10).
 *
 * Separates what changes between environments (on/off, rules, rollout, payload)
 * from what is environment-invariant (name, value type, kind, bucketing key).
 * The latter lives on the definition row in `feature_flags`; this VO lives in
 * `feature_flag_environment_state`.
 *
 * `{@see FeatureFlag::compose()}` merges a definition + this state into the
 * single `FeatureFlag` projection the evaluator and registry consume — so the
 * hot path is unchanged.
 */
final class FlagEnvironmentState
{
    /**
     * @param FlagRule[]                     $rules
     * @param array<string,int>|null         $variants       variant name → weight
     * @param array<string,FlagRule[]>|null  $variantRules   per-variant override rules
     * @param array<array-key,mixed>|null    $payload        JSON remote-config blob
     * @param Prerequisite[]                 $prerequisites
     */
    public function __construct(
        public readonly string $flagId,
        public readonly string $environment,
        public readonly bool $enabled,
        public readonly array $rules,
        public readonly ?array $variants,
        public readonly ?array $variantRules,
        public readonly ?RolloutSchedule $schedule,
        public readonly ?array $payload,
        public readonly ?string $requiredScope,
        public readonly array $prerequisites,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Synthesise an env state from an existing full-row FeatureFlag (back-compat: legacy
     * flags have no env state row — we build one on first write so they stay evaluable).
     */
    public static function fromFeatureFlag(FeatureFlag $flag, string $environment): self
    {
        return new self(
            flagId:        $flag->id,
            environment:   $environment,
            enabled:       $flag->enabled,
            rules:         $flag->rules,
            variants:      $flag->variants,
            variantRules:  $flag->variantRules,
            schedule:      $flag->schedule,
            payload:       $flag->payload,
            requiredScope: $flag->requiredScope,
            prerequisites: $flag->prerequisites,
            updatedAt:     $flag->updatedAt,
        );
    }

    /** Returns a copy with the enabled bit toggled. */
    public function withEnabled(bool $enabled): self
    {
        return new self(
            flagId:        $this->flagId,
            environment:   $this->environment,
            enabled:       $enabled,
            rules:         $this->rules,
            variants:      $this->variants,
            variantRules:  $this->variantRules,
            schedule:      $this->schedule,
            payload:       $this->payload,
            requiredScope: $this->requiredScope,
            prerequisites: $this->prerequisites,
            updatedAt:     new \DateTimeImmutable(),
        );
    }

    /** @param FlagRule[] $rules */
    public function withRules(array $rules): self
    {
        return new self(
            flagId:        $this->flagId,
            environment:   $this->environment,
            enabled:       $this->enabled,
            rules:         $rules,
            variants:      $this->variants,
            variantRules:  $this->variantRules,
            schedule:      $this->schedule,
            payload:       $this->payload,
            requiredScope: $this->requiredScope,
            prerequisites: $this->prerequisites,
            updatedAt:     new \DateTimeImmutable(),
        );
    }

    /** @param array<string,int>|null $variants */
    public function withVariants(?array $variants): self
    {
        return new self(
            flagId:        $this->flagId,
            environment:   $this->environment,
            enabled:       $this->enabled,
            rules:         $this->rules,
            variants:      $variants,
            variantRules:  $this->variantRules,
            schedule:      $this->schedule,
            payload:       $this->payload,
            requiredScope: $this->requiredScope,
            prerequisites: $this->prerequisites,
            updatedAt:     new \DateTimeImmutable(),
        );
    }

    public function withSchedule(?RolloutSchedule $schedule): self
    {
        return new self(
            flagId:        $this->flagId,
            environment:   $this->environment,
            enabled:       $this->enabled,
            rules:         $this->rules,
            variants:      $this->variants,
            variantRules:  $this->variantRules,
            schedule:      $schedule,
            payload:       $this->payload,
            requiredScope: $this->requiredScope,
            prerequisites: $this->prerequisites,
            updatedAt:     new \DateTimeImmutable(),
        );
    }

    public function toArray(): array
    {
        return [
            'flag_id'       => $this->flagId,
            'environment'   => $this->environment,
            'enabled'       => $this->enabled,
            'rules'         => array_map(fn(FlagRule $r) => $r->toArray(), $this->rules),
            'variants'      => $this->variants,
            'variant_rules' => $this->variantRules !== null
                ? array_map(
                    fn(array $rules) => array_map(fn(FlagRule $r) => $r->toArray(), $rules),
                    $this->variantRules,
                )
                : null,
            'schedule'      => $this->schedule?->toArray(),
            'payload'       => $this->payload,
            'required_scope' => $this->requiredScope,
            'prerequisites' => array_map(fn(Prerequisite $p) => $p->toArray(), $this->prerequisites),
            'updated_at'    => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            flagId:      (string) $data['flag_id'],
            environment: (string) $data['environment'],
            enabled:     (bool) $data['enabled'],
            rules:       array_map(fn(array $r) => FlagRule::fromArray($r), $data['rules'] ?? []),
            variants:    $data['variants'] ?? null,
            variantRules: isset($data['variant_rules']) && $data['variant_rules'] !== null
                ? array_map(
                    fn(array $rules) => array_map(fn(array $r) => FlagRule::fromArray($r), $rules),
                    $data['variant_rules'],
                )
                : null,
            schedule: isset($data['schedule']) && $data['schedule'] !== null
                ? RolloutSchedule::fromArray($data['schedule'])
                : null,
            payload:       $data['payload'] ?? null,
            requiredScope: $data['required_scope'] ?? null,
            prerequisites: array_map(
                fn(array $p) => Prerequisite::fromArray($p),
                $data['prerequisites'] ?? [],
            ),
            updatedAt: new \DateTimeImmutable($data['updated_at'] ?? 'now'),
        );
    }
}
