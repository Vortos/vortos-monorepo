<?php

declare(strict_types=1);

namespace Vortos\Audit\Search;

/**
 * Portable free-text search via case-insensitive LIKE across the searchable columns.
 *
 * Works on any SQL engine with no special index — the correct default off Postgres, and
 * the fallback when full-text search isn't warranted. Bounded by the reader's keyset LIMIT,
 * so even a full scan reads only one page's worth past the cursor.
 */
final class LikeSearchIndex implements AuditSearchIndexInterface
{
    /** @var list<string> columns concatenated for the match */
    private const COLUMNS = ['actor', 'action', 'target', 'context'];

    public function matchCondition(string $terms, string $paramKey = 'q'): array
    {
        $terms = trim($terms);
        if ($terms === '') {
            return ['', []];
        }

        // Escape LIKE wildcards in user input so 'a%b' matches literally.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $terms);
        $params  = [$paramKey => '%' . $escaped . '%'];

        $ors = array_map(
            static fn (string $col): string => "LOWER({$col}) LIKE LOWER(:{$paramKey}) ESCAPE '\\'",
            self::COLUMNS,
        );

        return ['(' . implode(' OR ', $ors) . ')', $params];
    }
}
