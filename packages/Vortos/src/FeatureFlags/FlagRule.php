<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * A single targeting rule.
 *
 * A flag's `rules` is an ordered list evaluated **first-match-wins / OR** (any
 * top-level rule matching turns the flag on). Boolean composition is expressed by a
 * rule of {@see TYPE_GROUP}, which carries a `combinator` (AND/OR) and nested
 * `children` (themselves rules, including further groups). Keeping the tree uniform
 * as `FlagRule[]` means storage, the wire format, and `withRules()` are unchanged and
 * legacy flat rules keep evaluating identically.
 */
final class FlagRule
{
    public const TYPE_USERS      = 'users';
    public const TYPE_ATTRIBUTE  = 'attribute';
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_GROUP      = 'group';
    public const TYPE_SEGMENT    = 'segment';

    public const CMB_AND = 'and';
    public const CMB_OR  = 'or';

    // Equality / membership / substring
    public const OP_EQUALS      = 'equals';
    public const OP_NOT_EQUALS  = 'not_equals';
    public const OP_IN          = 'in';
    public const OP_NOT_IN      = 'not_in';
    public const OP_CONTAINS    = 'contains';
    public const OP_STARTS_WITH = 'starts_with';
    public const OP_ENDS_WITH   = 'ends_with';
    public const OP_EXISTS      = 'exists';

    // Numeric comparison
    public const OP_GT  = 'gt';
    public const OP_LT  = 'lt';
    public const OP_GTE = 'gte';
    public const OP_LTE = 'lte';

    // Semantic versions
    public const OP_SEMVER_EQ = 'semver_eq';
    public const OP_SEMVER_GT = 'semver_gt';
    public const OP_SEMVER_LT = 'semver_lt';

    // Dates
    public const OP_DATE_BEFORE = 'date_before';
    public const OP_DATE_AFTER  = 'date_after';

    // Regex (ReDoS-bounded at evaluation)
    public const OP_REGEX = 'regex';

    /** All operators valid for an attribute rule — used to validate admin input. */
    public const ATTRIBUTE_OPERATORS = [
        self::OP_EQUALS, self::OP_NOT_EQUALS, self::OP_IN, self::OP_NOT_IN,
        self::OP_CONTAINS, self::OP_STARTS_WITH, self::OP_ENDS_WITH, self::OP_EXISTS,
        self::OP_GT, self::OP_LT, self::OP_GTE, self::OP_LTE,
        self::OP_SEMVER_EQ, self::OP_SEMVER_GT, self::OP_SEMVER_LT,
        self::OP_DATE_BEFORE, self::OP_DATE_AFTER, self::OP_REGEX,
    ];

    public function __construct(
        public readonly string  $type,
        public readonly array   $users      = [],
        public readonly ?string $attribute  = null,
        public readonly ?string $operator   = null,
        public readonly mixed   $value      = null,
        public readonly int     $percentage = 0,
        /** Which trust zone an attribute rule reads from: 'trusted' | 'untrusted' | 'any'. */
        public readonly string  $zone       = self::ZONE_ANY,
        /** For TYPE_GROUP: AND/OR over children. */
        public readonly ?string $combinator = null,
        /** For TYPE_GROUP: nested rules. @var FlagRule[] */
        public readonly array   $children   = [],
        /** For TYPE_SEGMENT: referenced segment name (resolved in Block 4). */
        public readonly ?string $segment    = null,
    ) {}

    public const ZONE_ANY       = 'any';
    public const ZONE_TRUSTED   = 'trusted';
    public const ZONE_UNTRUSTED = 'untrusted';

    public static function group(string $combinator, array $children): self
    {
        return new self(type: self::TYPE_GROUP, combinator: $combinator, children: $children);
    }

    public function toArray(): array
    {
        return array_filter([
            'type'       => $this->type,
            'users'      => $this->users ?: null,
            'attribute'  => $this->attribute,
            'operator'   => $this->operator,
            'value'      => $this->value,
            'percentage' => $this->percentage ?: null,
            'zone'       => $this->zone !== self::ZONE_ANY ? $this->zone : null,
            'combinator' => $this->combinator,
            'children'   => $this->children !== []
                ? array_map(fn(FlagRule $c) => $c->toArray(), $this->children)
                : null,
            'segment'    => $this->segment,
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
            zone:       $data['zone'] ?? self::ZONE_ANY,
            combinator: $data['combinator'] ?? null,
            children:   array_map(
                fn(array $c) => self::fromArray($c),
                $data['children'] ?? [],
            ),
            segment:    $data['segment'] ?? null,
        );
    }
}
