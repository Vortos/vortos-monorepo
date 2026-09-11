<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Ledger;

use Doctrine\DBAL\Connection;

/**
 * Postgres-backed {@see ExposureLedgerInterface}.
 *
 * ## One statement, not one per exposure
 *
 * A request that evaluates twenty flags produces twenty exposures, and twenty round trips to
 * write them would cost more than the request that produced them. Rows are folded into a
 * single multi-row `INSERT`, so the whole buffer costs one round trip regardless of size.
 *
 * ## `ON CONFLICT DO NOTHING` is load-bearing, not defensive
 *
 * It is the authoritative half of the dedupe. {@see DailyExposureDeduper} is only a
 * pre-filter, and it deliberately fails open, so duplicates reaching this point are expected
 * during a cache outage rather than exceptional. The primary key — `(day, subject_hash, flag,
 * variant)` — is what actually guarantees the grain, and the conflict clause is what makes a
 * duplicate cost nothing instead of failing the whole batch. Because the batch is one
 * statement, a plain insert would mean one duplicate row discards nineteen good ones.
 *
 * ## Chunked
 *
 * Parameter count is bounded by the driver, and the buffer is bounded per request but not
 * per flush (a worker draining several requests' exposures is legitimate). Chunking keeps
 * the statement inside the limit without the caller having to know there is one.
 */
final readonly class DatabaseExposureLedger implements ExposureLedgerInterface
{
    /** 6 parameters per row; 200 rows = 1,200 parameters, comfortably inside every driver's cap. */
    public const CHUNK_SIZE = 200;

    public function __construct(
        private Connection $connection,
        private string $table,
    ) {}

    public function append(array $exposures): int
    {
        if ($exposures === []) {
            return 0;
        }

        $appended = 0;

        foreach (array_chunk($exposures, self::CHUNK_SIZE) as $chunk) {
            $appended += $this->appendChunk($chunk);
        }

        return $appended;
    }

    /** @param list<LedgerExposure> $chunk */
    private function appendChunk(array $chunk): int
    {
        $placeholders = [];
        $parameters   = [];

        foreach ($chunk as $i => $exposure) {
            $placeholders[] = sprintf('(:d%1$d, :s%1$d, :f%1$d, :v%1$d, :o%1$d, :g%1$d, :t%1$d)', $i);

            $parameters['d' . $i] = $exposure->day;
            $parameters['s' . $i] = $exposure->subjectHash;
            $parameters['f' . $i] = $exposure->flag;
            $parameters['v' . $i] = $exposure->variant;
            $parameters['o' . $i] = $exposure->source->value;
            $parameters['g' . $i] = $exposure->groupKey;
            $parameters['t' . $i] = $exposure->firstSeenAt->format('Y-m-d H:i:s');
        }

        return (int) $this->connection->executeStatement(
            'INSERT INTO ' . $this->table
            . ' (day, subject_hash, flag, variant, source, group_key, first_seen_at) VALUES '
            . implode(', ', $placeholders)
            // The subject was already here today with this result. The first observation is
            // the one that counts — an experiment asks when a subject entered an arm, and
            // overwriting it with a later read would move that moment forward for no reason.
            . ' ON CONFLICT (day, subject_hash, flag, variant) DO NOTHING',
            $parameters,
        );
    }
}
