<?php

declare(strict_types=1);

namespace Vortos\Persistence\Access;

/**
 * A PostgreSQL role name the framework may write into DDL and GRANT statements.
 *
 * Restricted to the unquoted-identifier shape, so a name can never carry SQL however it reached the config, and
 * never `pg_…`, which PostgreSQL reserves for its predefined roles.
 */
final readonly class DatabaseRoleName
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $value) !== 1 || str_starts_with($value, 'pg_')) {
            throw new \InvalidArgumentException(sprintf(
                'Database role names must match ^[a-z_][a-z0-9_]{0,62}$ and must not start with "pg_", got "%s".',
                $value,
            ));
        }
    }
}
