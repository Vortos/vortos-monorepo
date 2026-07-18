<?php

declare(strict_types=1);

namespace Vortos\Search\Index;

use Vortos\Search\Enum\SearchDriver;

/**
 * Postgres full-text matching with a trigram fuzzy fallback.
 *
 * Primary match is the generated, weighted `search_vector` tsvector (title = A, subtitle = B,
 * body = C — installed by `search:pg:install`) against `websearch_to_tsquery`, ranked with
 * `ts_rank_cd`. When the tsquery yields nothing (typos, sub-word fragments, partial ids) the
 * predicate also admits trigram-similar `keywords` via `word_similarity`, which scores the
 * query against the closest WORD in the blob (plain `similarity` would dilute a single fuzzy
 * word across the whole field) — so "johnsen" still finds "Johnson". The 'simple' config (no
 * stemming/stop-words) keeps identifier-like tokens matching verbatim.
 *
 * Both the GIN index on `search_vector` and the `gin_trgm_ops` index on `keywords` are created
 * out-of-band by `search:pg:install` — the portable schema-diff migration seam can't express
 * expression/opclass indexes.
 */
final class PostgresFtsSearchDriver implements SearchIndexDriver
{
    /** Word-similarity floor for the trigram fallback; below this a token is not "close enough". */
    private const TRIGRAM_THRESHOLD = 0.4;

    public function compile(string $terms, string $paramKey = 'q'): SearchPredicate
    {
        $terms = trim($terms);
        if ($terms === '') {
            return new SearchPredicate();
        }

        $params = [$paramKey => $terms];

        $fts     = "search_vector @@ websearch_to_tsquery('simple', :{$paramKey})";
        $trigram = "word_similarity(:{$paramKey}, keywords) > " . self::TRIGRAM_THRESHOLD;
        $where   = "({$fts} OR {$trigram})";

        // FTS relevance dominates; word-similarity breaks ties and carries fuzzy-only hits.
        $rank =
            "(ts_rank_cd(search_vector, websearch_to_tsquery('simple', :{$paramKey}))"
            . " + word_similarity(:{$paramKey}, keywords))";

        return new SearchPredicate($where, $rank, $params);
    }

    public function name(): string
    {
        return SearchDriver::PostgresFts->value;
    }
}
