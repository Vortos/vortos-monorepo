<?php

declare(strict_types=1);

namespace Vortos\Authorization\Decision;

final class AuthorizationDecision
{
    private function __construct(
        private readonly bool $allowed,
        private readonly string $reason,
        private readonly ?string $requiredPermission = null,
    ) {
    }

    public static function allow(?string $permission = null): self
    {
        return new self(true, AuthorizationDecisionReason::Allowed->value, $permission);
    }

    public static function deny(AuthorizationDecisionReason|string $reason, ?string $permission = null): self
    {
        return new self(
            false,
            $reason instanceof AuthorizationDecisionReason ? $reason->value : $reason,
            $permission,
        );
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function denied(): bool
    {
        return !$this->allowed;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function requiredPermission(): ?string
    {
        return $this->requiredPermission;
    }
}
