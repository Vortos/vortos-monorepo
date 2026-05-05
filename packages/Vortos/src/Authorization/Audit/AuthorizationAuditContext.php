<?php

declare(strict_types=1);

namespace Vortos\Authorization\Audit;

final readonly class AuthorizationAuditContext
{
    public function __construct(
        public ?string $requestId = null,
        public ?string $correlationId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $httpMethod = null,
        public ?string $path = null,
        public ?string $route = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toMetadata(): array
    {
        return array_filter([
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'http_method' => $this->httpMethod,
            'path' => $this->path,
            'route' => $this->route,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
