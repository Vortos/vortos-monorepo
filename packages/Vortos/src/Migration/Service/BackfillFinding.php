<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

final readonly class BackfillFinding
{
    private function __construct(
        public bool $blocked,
        public string $reason,
        public string $statement,
    ) {}

    public static function unboundedUpdate(string $statement): self
    {
        return new self(
            true,
            'Unbounded UPDATE without WHERE clause or LIMIT. Batch the update or declare as #[DeployPhase(Contract)] with #[AllowFullTableRewrite].',
            $statement,
        );
    }

    public static function unboundedDelete(string $statement): self
    {
        return new self(
            true,
            'Unbounded DELETE without WHERE clause or LIMIT. Batch the delete or declare as #[DeployPhase(Contract)] with #[AllowFullTableRewrite].',
            $statement,
        );
    }

    public static function allowed(string $statement): self
    {
        return new self(false, 'Allowed: explicit opt-out via #[AllowFullTableRewrite].', $statement);
    }
}
