<?php

declare(strict_types=1);

namespace Vortos\Migration\Attribute;

/**
 * Opt out of the pg.index.non-idempotent-concurrent safety rule for a single migration.
 *
 * Apply to a migration whose CREATE INDEX CONCURRENTLY statement is intentionally not
 * guarded by IF NOT EXISTS — meaning the author accepts responsibility for cleaning up
 * an INVALID index left behind by a failed build before re-running.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AllowNonIdempotentConcurrent
{
}
