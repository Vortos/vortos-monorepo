<?php

declare(strict_types=1);

namespace Vortos\Audit\Query;

/**
 * Read side of the trail: keyset-paginated, filtered queries. Separate from the write
 * store and the retention source so each concern stays narrow.
 */
interface AuditQueryInterface
{
    public function page(AuditQuery $query): AuditPage;

    /**
     * Facet counts (by action / sensitivity / outcome) over the filtered set, ignoring
     * pagination — for a console's faceted filter rail.
     */
    public function facets(AuditQuery $query): AuditFacets;
}
