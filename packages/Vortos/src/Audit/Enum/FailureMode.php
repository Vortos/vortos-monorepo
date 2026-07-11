<?php

declare(strict_types=1);

namespace Vortos\Audit\Enum;

/**
 * What the async recorder does when it cannot enqueue an event for ingestion.
 *
 *   - Block: rethrow, so the calling operation fails rather than silently losing an
 *            audit record. Correct for high-sensitivity/compliance actions.
 *   - Drop:  log and continue, so a broker outage never takes down the request path.
 *            Correct when availability outranks completeness for low-value events.
 */
enum FailureMode: string
{
    case Block = 'block';
    case Drop  = 'drop';
}
