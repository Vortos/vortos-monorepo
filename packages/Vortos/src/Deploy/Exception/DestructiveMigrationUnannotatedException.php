<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

/**
 * Thrown at deploy-runtime when a pending migration contains destructive DDL
 * (DROP/RENAME/TYPE change/SET NOT NULL/TRUNCATE …) but carries no #[DeployPhase] declaration.
 * Destructiveness must not be silently classified Expand — the operator gets a precise
 * remediation instead of a generic contract error.
 */
final class DestructiveMigrationUnannotatedException extends DeployException
{
    /** @var list<string> */
    public readonly array $offendingMigrations;

    /** @param list<string> $offendingMigrations */
    public function __construct(array $offendingMigrations)
    {
        $this->offendingMigrations = $offendingMigrations;
        $ids = implode(', ', $offendingMigrations);

        parent::__construct(sprintf(
            'Deploy refused: pending migration(s) [%s] contain destructive DDL without a #[DeployPhase] declaration. '
            . 'Annotate them #[DeployPhase(MigrationPhase::Contract)] and ship them in a later deploy behind the '
            . 'soak/flag gate, or — if the rewrite is intentional and safe — mark them #[DeployPhase(MigrationPhase::Expand)] '
            . 'with #[AllowFullTableRewrite]. Destructiveness is never inferred as safe.',
            $ids,
        ));
    }
}
