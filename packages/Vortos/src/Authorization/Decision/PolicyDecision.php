<?php

declare(strict_types=1);

namespace Vortos\Authorization\Decision;

/**
 * Optional richer return type for {@see \Vortos\Authorization\Contract\PolicyInterface::can()}.
 *
 * A policy may still return a bare bool (true => allow, false => deny with the generic
 * ResourceDenied reason). Returning a PolicyDecision lets a denial carry an explainable,
 * auditable reason — e.g. deny('tournament_published').
 *
 * A policy can only ever RESTRICT: the engine reaches it only after RBAC has() has
 * already granted the permission, so allow() here confirms, it never re-authorizes.
 */
final class PolicyDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(?string $reason = null): self
    {
        return new self(false, $reason);
    }
}
