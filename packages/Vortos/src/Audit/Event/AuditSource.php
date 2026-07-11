<?php

declare(strict_types=1);

namespace Vortos\Audit\Event;

/**
 * Request-context provenance for an audited action: where it came from. Captured by
 * middleware (P9) so every event can be tied back to an IP, device, session, and the
 * originating request id for cross-log correlation.
 */
final readonly class AuditSource
{
    public function __construct(
        public string  $ip = '',
        public string  $userAgent = '',
        public ?string $sessionId = null,
        public ?string $requestId = null,
        public ?string $deviceId = null,
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ip'         => $this->ip,
            'user_agent' => $this->userAgent,
            'session_id' => $this->sessionId,
            'request_id' => $this->requestId,
            'device_id'  => $this->deviceId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ip:        (string) ($data['ip'] ?? ''),
            userAgent: (string) ($data['user_agent'] ?? ''),
            sessionId: isset($data['session_id']) ? (string) $data['session_id'] : null,
            requestId: isset($data['request_id']) ? (string) $data['request_id'] : null,
            deviceId:  isset($data['device_id']) ? (string) $data['device_id'] : null,
        );
    }
}
