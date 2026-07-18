<?php

declare(strict_types=1);

namespace Vortos\Search\Index;

use Vortos\Search\Enum\SearchDriver;

/**
 * Portable free-text matching via case-insensitive LIKE across the stored text columns.
 *
 * Runs on any SQL engine with no special index — the correct default, and the fallback when
 * full-text isn't warranted. Bounded by the reader's LIMIT, so even a scan reads only a page's
 * worth of rows. Relevance is coarse: an exact-ish title match is boosted above a body-only
 * match via a CASE rank, which is enough to keep the obvious hit on top.
 */
final class PortableLikeSearchDriver implements SearchIndexDriver
{
    /** Columns scanned for a match, widest-net order. */
    private const COLUMNS = ['title', 'subtitle', 'keywords', 'body'];

    public function compile(string $terms, string $paramKey = 'q'): SearchPredicate
    {
        $terms = trim($terms);
        if ($terms === '') {
            return new SearchPredicate();
        }

        // Escape LIKE wildcards so 'a%b' matches literally.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $terms);
        $like    = '%' . $escaped . '%';
        $params  = [$paramKey => $like];

        $ors = array_map(
            static fn (string $col): string => "LOWER({$col}) LIKE LOWER(:{$paramKey}) ESCAPE '\\'",
            self::COLUMNS,
        );
        $where = '(' . implode(' OR ', $ors) . ')';

        // Title hit ranks above subtitle/keyword hit ranks above a body-only hit.
        $rank =
            "(CASE WHEN LOWER(title) LIKE LOWER(:{$paramKey}) ESCAPE '\\' THEN 3"
            . " WHEN LOWER(subtitle) LIKE LOWER(:{$paramKey}) ESCAPE '\\' THEN 2"
            . " WHEN LOWER(keywords) LIKE LOWER(:{$paramKey}) ESCAPE '\\' THEN 2"
            . ' ELSE 1 END)';

        return new SearchPredicate($where, $rank, $params);
    }

    public function name(): string
    {
        return SearchDriver::Portable->value;
    }
}
