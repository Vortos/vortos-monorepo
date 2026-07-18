<?php

declare(strict_types=1);

namespace Vortos\Search\Enum;

/**
 * Which index driver backs matching + ranking over search_document.
 *
 * {@see \Vortos\Search\Index\PortableLikeSearchDriver} runs on any SQL engine with no special
 * index — the correct default. {@see \Vortos\Search\Index\PostgresFtsSearchDriver} adds
 * weighted full-text ranking + trigram fuzzy matching on Postgres (opt-in; pair it with
 * `search:pg:install` for the generated tsvector column + GIN indexes).
 */
enum SearchDriver: string
{
    case Portable = 'portable';
    case PostgresFts = 'postgres_fts';
}
