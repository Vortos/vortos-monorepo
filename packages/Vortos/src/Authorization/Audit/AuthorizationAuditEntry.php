<?php

declare(strict_types=1);

namespace Vortos\Authorization\Audit;

final readonly class AuthorizationAuditEntry
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $actorUserId,
        public string $action,
        public ?string $targetUserId,
        public ?string $role,
        public ?string $permission,
        public ?string $reason,
        public array $metadata,
        public ?string $requestId,
        public ?string $correlationId,
        public ?string $ipAddress,
        public ?string $userAgent,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function create(
        string $actorUserId,
        string $action,
        ?string $targetUserId = null,
        ?string $role = null,
        ?string $permission = null,
        ?string $reason = null,
        array $metadata = [],
        ?AuthorizationAuditContext $context = null,
    ): self {
        $metadata = array_merge($context?->toMetadata() ?? [], $metadata);

        return new self(
            bin2hex(random_bytes(16)),
            $actorUserId,
            $action,
            $targetUserId,
            $role,
            $permission,
            $reason,
            $metadata,
            $context?->requestId,
            $context?->correlationId,
            $context?->ipAddress,
            $context?->userAgent,
            new \DateTimeImmutable(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'id' => $this->id,
            'actor_user_id' => $this->actorUserId,
            'action' => $this->action,
            'target_user_id' => $this->targetUserId,
            'role' => $this->role,
            'permission' => $this->permission,
            'reason' => $this->reason,
            'metadata' => json_encode($this->metadata, JSON_THROW_ON_ERROR),
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'created_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
