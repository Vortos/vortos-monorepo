<?php

declare(strict_types=1);

namespace Vortos\Search\Index;

/**
 * Pluggable matching + ranking strategy over the search_document table.
 *
 * The framework ships two: {@see PortableLikeSearchDriver} (any SQL engine, no special index —
 * the default) and {@see PostgresFtsSearchDriver} (weighted full-text + trigram fuzzy on
 * Postgres). An app can swap an external engine (OpenSearch/Meilisearch) by implementing this
 * against its own index and returning a membership predicate over `entity_id`.
 *
 * Everything else — tenant RLS, permission/owner filtering, pagination, deep-links — lives in
 * the reader and is identical across drivers, so switching drivers changes relevance only,
 * never behaviour or scoping.
 */
interface SearchIndexDriver
{
    /**
     * Compile the raw user query into a WHERE/ORDER predicate over the search_document columns
     * (`title`, `subtitle`, `body`, `keywords`, and — Postgres only — the generated
     * `search_vector`). The reader guarantees $paramKey is unique within its statement.
     *
     * @param string $paramKey base bind-parameter name the driver may derive its params from
     */
    public function compile(string $terms, string $paramKey = 'q'): SearchPredicate;

    /** Enum value identifying this driver (diagnostics + doctor output). */
    public function name(): string;
}
