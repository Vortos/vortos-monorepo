<?php
declare(strict_types=1);

namespace Vortos\Auth\Audit;

/**
 * Immutable audit log entry.
 * Captures who did what, when, from where.
 */
final readonly class AuditEntry
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $action,
        public ?string $resourceId,
        public string $ipAddress,
        public string $userAgent,
        public \DateTimeImmutable $occurredAt,
        public array $metadata = [],
    ) {}

    public static function create(
        string $userId,
        string $action,
        ?string $resourceId = null,
        string $ipAddress = '',
        string $userAgent = '',
        array $metadata = [],
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            userId: $userId,
            action: $action,
            resourceId: $resourceId,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            occurredAt: new \DateTimeImmutable(),
            metadata: $metadata,
        );
    }

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'user_id'    => $this->userId,
            'action'     => $this->action,
            'resource'   => $this->resourceId,
            'ip'         => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'metadata'   => json_encode($this->metadata),
            'created_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
