<?php

declare(strict_types=1);

namespace Vortos\Release\Manifest;

/**
 * Thrown when the release-ledger schema is absent at record time (G9). On a fresh production DB the
 * ledger tables are created by `vortos:migrate`; if a manifest write reaches a DB where migrations
 * have not run, fail closed with an actionable message rather than surfacing a raw SQLSTATE 42P01.
 *
 * The generated deploy script runs provisioning (which migrates) before record-manifest, so this is
 * defense-in-depth against a misordered or hand-run sequence — never the happy path.
 */
final class ManifestSchemaMissingException extends \RuntimeException
{
    public static function forTable(string $table, \Throwable $previous): self
    {
        return new self(
            sprintf(
                'Release-ledger table "%s" does not exist. Run "vortos:migrate" against this database '
                . 'before recording a build manifest (the deploy provisioning step does this first).',
                $table,
            ),
            0,
            $previous,
        );
    }
}
