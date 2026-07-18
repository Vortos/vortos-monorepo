<?php

declare(strict_types=1);

namespace Vortos\Search\Observability;

/**
 * The metrics the search engine emits. Apps MUST declare these in their metrics registry
 * (e.g. Symfony `config/metrics.php`) or the emit path throws when the backend rejects an
 * undeclared metric — spread this array into that file rather than hand-copying the names.
 */
final class SearchMetricDefinitions
{
    /** @return array<string, array{type: string, help: string, labels?: list<string>}> */
    public static function all(): array
    {
        return [
            'search_index_upsert_total' => [
                'type'   => 'counter',
                'help'   => 'Documents (re)indexed into search_document.',
                'labels' => ['type'],
            ],
            'search_index_delete_total' => [
                'type'   => 'counter',
                'help'   => 'Documents removed from search_document.',
                'labels' => ['type'],
            ],
            'search_query_total' => [
                'type'   => 'counter',
                'help'   => 'Search queries served.',
                'labels' => ['hit', 'cache'],
            ],
            'search_query_latency_seconds' => [
                'type'   => 'histogram',
                'help'   => 'Wall time to serve a search query.',
            ],
        ];
    }
}
