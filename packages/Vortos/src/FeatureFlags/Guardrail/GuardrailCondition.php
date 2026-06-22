<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail;

final readonly class GuardrailCondition
{
    public const COMBINATOR_AND = 'AND';
    public const COMBINATOR_OR  = 'OR';

    /**
     * @param GuardrailCondition[] $children non-empty for group nodes
     */
    public function __construct(
        public string $id,
        public ?string $combinator,
        public ?GuardrailMetricKind $metricKind,
        public ?string $customMetricName,
        public ?float $threshold,
        public ?string $comparisonOperator,
        public int $sortOrder,
        public array $children = [],
    ) {}

    public function isGroup(): bool
    {
        return !empty($this->children);
    }

    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'combinator'          => $this->combinator,
            'metric_kind'         => $this->metricKind?->value,
            'custom_metric_name'  => $this->customMetricName,
            'threshold'           => $this->threshold,
            'comparison_operator' => $this->comparisonOperator,
            'sort_order'          => $this->sortOrder,
            'children'            => array_map(fn(self $c) => $c->toArray(), $this->children),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id:                 (string) $data['id'],
            combinator:         isset($data['combinator']) ? (string) $data['combinator'] : null,
            metricKind:         isset($data['metric_kind']) && $data['metric_kind'] !== null
                                    ? GuardrailMetricKind::from($data['metric_kind'])
                                    : null,
            customMetricName:   isset($data['custom_metric_name']) ? (string) $data['custom_metric_name'] : null,
            threshold:          isset($data['threshold']) && $data['threshold'] !== null
                                    ? (float) $data['threshold']
                                    : null,
            comparisonOperator: isset($data['comparison_operator']) ? (string) $data['comparison_operator'] : null,
            sortOrder:          (int) ($data['sort_order'] ?? 0),
            children:           array_map(
                fn(array $c) => self::fromArray($c),
                $data['children'] ?? [],
            ),
        );
    }
}
