<?php

declare(strict_types=1);

namespace Vortos\Audit\Event;

/**
 * The typed resource an audited action acted upon (nullable — some actions have no
 * single target, e.g. a login). `label` is a human-friendly denormalised snapshot so
 * the trail stays readable even after the target is renamed or deleted.
 */
final readonly class AuditTarget
{
    public function __construct(
        public string  $type,
        public string  $id,
        public ?string $label = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type'  => $this->type,
            'id'    => $this->id,
            'label' => $this->label,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type:  (string) $data['type'],
            id:    (string) $data['id'],
            label: isset($data['label']) ? (string) $data['label'] : null,
        );
    }
}
