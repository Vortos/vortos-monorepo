<?php

declare(strict_types=1);

namespace Vortos\Search\Backfill;

use Vortos\Search\Document\SearchDocument;

/**
 * Streams the current, authoritative set of documents for one type so the index can be built
 * from scratch or reconciled — for first roll-out (nothing has emitted events yet), after a
 * schema/relevance change, or as a periodic drift safety-net.
 *
 * Each searchable type provides one of these (tagged; discovered like projectors). Implementations
 * MUST yield lazily (generator / batched cursor) so rebuilding millions of rows stays O(1) in
 * memory. The same {@see SearchDocument} mapping the projector uses should be reused here.
 */
interface SearchBackfillSourceInterface
{
    /** The doc type this source rebuilds (matches the projector's documents' type). */
    public function type(): string;

    /**
     * Lazily yield every current document, optionally narrowed to one tenant.
     *
     * @param string|null $tenantId restrict to this org, or null for all tenants
     *
     * @return iterable<SearchDocument>
     */
    public function documents(?string $tenantId = null): iterable;
}
