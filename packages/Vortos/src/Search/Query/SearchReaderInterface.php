<?php

declare(strict_types=1);

namespace Vortos\Search\Query;

/**
 * Reads ranked, scope-filtered hits from the index. The default DBAL implementation composes
 * the active {@see \Vortos\Search\Index\SearchIndexDriver} predicate with the tenant/permission/
 * owner scope into one paginated query; an external-engine app can implement this directly.
 */
interface SearchReaderInterface
{
    public function search(SearchQuery $query, SearchScope $scope): SearchResults;
}
