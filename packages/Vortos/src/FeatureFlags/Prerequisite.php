<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * A dependency on another flag: this flag is only eligible when `flag` resolves to
 * `expectedValue` for the same context. Cycles (A→B→A) are rejected at write time by
 * {@see Validation\FlagValidator}; the evaluator additionally depth-guards as defence.
 */
final class Prerequisite
{
    public function __construct(
        public readonly string $flag,
        public readonly FlagValue $expectedValue,
    ) {}

    public function toArray(): array
    {
        return [
            'flag'       => $this->flag,
            'value_type' => $this->expectedValue->type->value,
            'value'      => $this->expectedValue->encode(),
        ];
    }

    public static function fromArray(array $data): self
    {
        $type = isset($data['value_type'])
            ? FlagValueType::from($data['value_type'])
            : FlagValueType::Bool;

        return new self(
            flag:          $data['flag'],
            expectedValue: FlagValue::decode($type, $data['value'] ?? null),
        );
    }

    /** Convenience for the common "prerequisite flag must be on" case. */
    public static function on(string $flag): self
    {
        return new self($flag, FlagValue::bool(true));
    }
}
