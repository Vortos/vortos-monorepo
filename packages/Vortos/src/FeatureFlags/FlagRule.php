<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

final class FlagRule
{
    public const TYPE_USERS      = 'users';
    public const TYPE_ATTRIBUTE  = 'attribute';
    public const TYPE_PERCENTAGE = 'percentage';

    public const OP_EQUALS     = 'equals';
    public const OP_NOT_EQUALS = 'not_equals';
    public const OP_IN         = 'in';
    public const OP_NOT_IN     = 'not_in';
    public const OP_CONTAINS   = 'contains';

    public function __construct(
        public readonly string  $type,
        public readonly array   $users      = [],
        public readonly ?string $attribute  = null,
        public readonly ?string $operator   = null,
        public readonly mixed   $value      = null,
        public readonly int     $percentage = 0,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'type'       => $this->type,
            'users'      => $this->users ?: null,
            'attribute'  => $this->attribute,
            'operator'   => $this->operator,
            'value'      => $this->value,
            'percentage' => $this->percentage ?: null,
        ], fn($v) => $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type:       $data['type'],
            users:      $data['users'] ?? [],
            attribute:  $data['attribute'] ?? null,
            operator:   $data['operator'] ?? null,
            value:      $data['value'] ?? null,
            percentage: (int) ($data['percentage'] ?? 0),
        );
    }
}
