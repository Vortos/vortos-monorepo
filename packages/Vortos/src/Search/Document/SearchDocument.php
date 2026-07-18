<?php

declare(strict_types=1);

namespace Vortos\Search\Document;

/**
 * One indexable thing — the framework's whole notion of "a searchable item".
 *
 * The engine knows nothing about applications, payments or tournaments; it only stores and
 * ranks {@see SearchDocument}s. The application maps each of its aggregates to one of these
 * inside a {@see \Vortos\Search\Projection\SearchableProjection}, which is the single seam
 * that keeps domain knowledge out of the framework.
 *
 * ## Scoping (two independent axes)
 *
 *  - {@see $tenantId} is the org boundary. It is written on every row and — with the Postgres
 *    driver + `search:pg:install` + `vortos/vortos-tenant` — enforced by row-level security,
 *    so one org physically cannot read another org's rows.
 *  - {@see $permission} + {@see $ownerMemberId} are the within-org visibility axis. A row with
 *    an owner is PERSONAL (only that member sees it); a row without one is ORG-SHARED (any
 *    member holding {@see $permission} sees it). The query filters on both so search never
 *    surfaces something the caller could not open.
 *
 * {@see $deeplink} is resolved AT INDEX TIME (the projector already holds the canonical id),
 * so every hit is directly navigable with no second lookup at query time.
 */
final class SearchDocument
{
    /**
     * @param string      $type          stable app-defined kind, e.g. "application" | "payment"
     * @param string      $entityId      the source aggregate id (unique within tenant+type)
     * @param string      $tenantId      owning org — the RLS boundary
     * @param string      $title         primary label (highest search weight)
     * @param string      $subtitle      secondary line shown under the title (medium weight)
     * @param string      $body          extra searchable text not necessarily shown (low weight)
     * @param string      $deeplink      app route to open the hit, resolved now, e.g. "/applications/42"
     * @param string|null $permission    permission required to open an ORG-SHARED row; null = no gate
     * @param string|null $ownerMemberId set → PERSONAL row visible only to this member; null = org-shared
     * @param list<string> $keywords     extra fuzzy-match tokens (emails, refs, ids) not in the title
     * @param array<string,scalar|null> $meta app payload echoed back on the hit (icon hints, status…)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $entityId,
        public readonly string $tenantId,
        public readonly string $title,
        public readonly string $subtitle = '',
        public readonly string $body = '',
        public readonly string $deeplink = '',
        public readonly ?string $permission = null,
        public readonly ?string $ownerMemberId = null,
        public readonly array $keywords = [],
        public readonly array $meta = [],
    ) {
        if ($type === '') {
            throw new \InvalidArgumentException('SearchDocument type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('SearchDocument entityId must not be empty.');
        }
        if ($tenantId === '') {
            throw new \InvalidArgumentException('SearchDocument tenantId must not be empty.');
        }
    }

    /** All fuzzy-match tokens as one string: keywords plus the human-facing lines. */
    public function keywordBlob(): string
    {
        return trim(implode(' ', [...$this->keywords, $this->title, $this->subtitle]));
    }
}
