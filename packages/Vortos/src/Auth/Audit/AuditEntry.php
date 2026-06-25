<?php
declare(strict_types=1);

namespace Vortos\Auth\Audit;

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
        public ?int $sequence = null,
        public ?string $prevHash = null,
        public ?string $contentHash = null,
        public ?string $signature = null,
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
            id: (string) new \Symfony\Component\Uid\UuidV7(),
            userId: $userId,
            action: $action,
            resourceId: $resourceId,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            occurredAt: new \DateTimeImmutable(),
            metadata: $metadata,
        );
    }

    public function withIntegrity(int $sequence, string $prevHash, string $contentHash, string $signature): self
    {
        return new self(
            id: $this->id,
            userId: $this->userId,
            action: $this->action,
            resourceId: $this->resourceId,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            occurredAt: $this->occurredAt,
            metadata: $this->metadata,
            sequence: $sequence,
            prevHash: $prevHash,
            contentHash: $contentHash,
            signature: $signature,
        );
    }

    public function isChained(): bool
    {
        return $this->sequence !== null
            && $this->prevHash !== null
            && $this->contentHash !== null
            && $this->signature !== null;
    }

    public function toArray(): array
    {
        $data = [
            'id'         => $this->id,
            'user_id'    => $this->userId,
            'action'     => $this->action,
            'resource'   => $this->resourceId,
            'ip'         => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'metadata'   => json_encode($this->metadata),
            'created_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];

        if ($this->isChained()) {
            $data['sequence'] = $this->sequence;
            $data['prev_hash'] = $this->prevHash;
            $data['content_hash'] = $this->contentHash;
            $data['signature'] = $this->signature;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            userId: (string) $data['user_id'],
            action: (string) $data['action'],
            resourceId: isset($data['resource']) ? (string) $data['resource'] : null,
            ipAddress: (string) ($data['ip'] ?? ''),
            userAgent: (string) ($data['user_agent'] ?? ''),
            occurredAt: new \DateTimeImmutable((string) $data['created_at']),
            metadata: is_string($data['metadata'] ?? null) ? json_decode($data['metadata'], true) : (array) ($data['metadata'] ?? []),
            sequence: isset($data['sequence']) ? (int) $data['sequence'] : null,
            prevHash: isset($data['prev_hash']) ? (string) $data['prev_hash'] : null,
            contentHash: isset($data['content_hash']) ? (string) $data['content_hash'] : null,
            signature: isset($data['signature']) ? (string) $data['signature'] : null,
        );
    }
}
