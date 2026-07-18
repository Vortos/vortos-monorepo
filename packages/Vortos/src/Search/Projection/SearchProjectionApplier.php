<?php

declare(strict_types=1);

namespace Vortos\Search\Projection;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\Search\Index\SearchIndexWriterInterface;

/**
 * Applies a domain event's projection outcomes to the index — the bridge between the pure
 * {@see SearchProjectorRegistry} and the {@see SearchIndexWriterInterface}.
 *
 * This is what the app's Kafka event handler calls (one line: `$applier->apply($event)`), so the
 * framework never needs to know the app's messaging wiring, and indexing stays decoupled from
 * the write path — a slow or failed index update never blocks the domain transaction.
 */
final class SearchProjectionApplier
{
    public function __construct(
        private readonly SearchProjectorRegistry $registry,
        private readonly SearchIndexWriterInterface $writer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @return int number of index mutations applied for this event */
    public function apply(object $event): int
    {
        $applied = 0;
        foreach ($this->registry->project($event) as $outcome) {
            try {
                if ($outcome instanceof SearchUpsert) {
                    $this->writer->upsert($outcome->document);
                } else {
                    $this->writer->delete($outcome->type, $outcome->entityId, $outcome->tenantId);
                }
                $applied++;
            } catch (\Throwable $e) {
                // One bad document must not poison the rest of the batch; indexing is a
                // rebuildable read-model, so log and carry on.
                $this->logger->error('search: failed to apply projection outcome', [
                    'event'     => $event::class,
                    'outcome'   => $outcome::class,
                    'exception' => $e,
                ]);
            }
        }

        return $applied;
    }
}
