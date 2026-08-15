<?php

declare(strict_types=1);

namespace Vortos\Backup\Catalog;

use DateTimeImmutable;
use Vortos\Backup\Domain\DatabaseEngine;

/**
 * One aggregate question about archived WAL: how many segments, and how many bytes at rest.
 *
 * A separate interface with a single method, rather than another method on
 * {@see RetentionCatalogInterface} or {@see BackupCatalogReadModelInterface}, for two reasons.
 *
 * The narrowness is the point, and it is the same lesson those two already encode. Retention was
 * once written against a catalogue accessor that returned everything, hydrated ~18,000 artifacts
 * per tick, hit the 128M limit, and left production without retention for days until supervisord
 * gave up on it permanently. The rule that came out of that is that anything touching the WAL slice
 * — the unbounded one — must ask the database for exactly the shape it needs. A caller that only
 * wants two integers should not be handed an interface that can also return ten thousand rows.
 *
 * And the consumer is a health probe, not retention. Widening the retention contract to serve it
 * would make every retention test double implement a method retention never calls, which is how
 * interfaces drift into being a list of everything one class happens to do.
 */
interface WalVolumeReadModelInterface
{
    /**
     * Segment count and total stored bytes for WAL archived at or after $from.
     *
     * Aggregated in the database, never by summing a hydrated list. Both values are zero for an
     * empty window, which is a normal state and must stay distinguishable from an error — see the
     * COALESCE in the DBAL implementation.
     *
     * @return array{segments: int, bytes: int}
     */
    public function walVolumeSince(
        DatabaseEngine $engine,
        string $environment,
        DateTimeImmutable $from,
    ): array;
}
