<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

use Vortos\Backup\Domain\Exception\UnknownEngineException;

/**
 * The database engine a backup target dumps.
 *
 * This is an *engine* identity (what kind of database), never a *provider* identity
 * (R2/S3/Cloudflare) — so it is allowed to appear in core. Drivers live under
 * `Driver/Postgres` and `Driver/Mongo` and are selected by their #[AsDriver] key,
 * which mirrors these values.
 */
enum DatabaseEngine: string
{
    case Postgres = 'postgres';
    case Mongo = 'mongo';

    /**
     * @throws UnknownEngineException when $value names no known engine — fail closed.
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw UnknownEngineException::forValue($value, self::all());
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_map(static fn (self $e): string => $e->value, self::cases());
    }
}
