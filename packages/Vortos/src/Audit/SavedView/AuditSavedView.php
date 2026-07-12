<?php

declare(strict_types=1);

namespace Vortos\Audit\SavedView;

use Symfony\Component\Uid\UuidV7;

/**
 * A named, persisted filter set for the audit consoles ("Refunds — July", "Admin actions").
 *
 * `filters` is the opaque set of query params the console re-applies (action/actionPrefix/
 * search/sensitivity/outcome/date range/…). It is scoped: an org-owned view carries its
 * tenantId; a platform view has tenantId = null. `ownerId` is the user who saved it.
 */
final readonly class AuditSavedView
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public string             $id,
        public ?string            $tenantId,
        public string             $ownerId,
        public string             $name,
        public array              $filters,
        public \DateTimeImmutable $createdAt,
    ) {}

    /**
     * @param array<string, mixed> $filters
     */
    public static function create(?string $tenantId, string $ownerId, string $name, array $filters): self
    {
        return new self((string) new UuidV7(), $tenantId, $ownerId, $name, $filters, new \DateTimeImmutable());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'tenantId'  => $this->tenantId,
            'ownerId'   => $this->ownerId,
            'name'      => $this->name,
            'filters'   => (object) $this->filters,
            'createdAt' => $this->createdAt->format('Y-m-d\TH:i:sP'),
        ];
    }
}
