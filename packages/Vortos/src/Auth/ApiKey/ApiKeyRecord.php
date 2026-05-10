<?php

declare(strict_types=1);

namespace Vortos\Auth\ApiKey;

final readonly class ApiKeyRecord
{
    public function __construct(
        public string     $id,
        public string     $userId,
        public string     $name,
        public string     $hashedKey,
        /** @var list<string> */
        public array      $scopes,
        public bool       $active,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $lastUsedAt,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function hasAllScopes(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (!$this->hasScope($scope)) {
                return false;
            }
        }
        return true;
    }
}
