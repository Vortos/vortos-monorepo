# vortos-search

Enterprise-grade, event-fed **global search** for Vortos apps. One tenant-scoped, RLS-isolated
`search_documents` projection, fed from your domain events through a single discoverable
contract. Adding a searchable **type** is one class; new **instances** are indexed automatically
because they arrive on the events you already emit.

The framework owns the engine (matching, ranking, scoping, indexing, backfill). **The app owns
what is searchable and where each result deep-links** — the framework never learns what an
"application" is.

## Why it exists

A hand-maintained search list rots the moment someone adds a feature. This package makes search
a **read-model** off the event bus — the same pattern as the audit spine — so it stays correct
by construction, rebuilds from scratch on demand, and scales independently of the write path.

## Design at a glance

```
domain events ──▶ SearchableProjection (app) ──▶ SearchProjectionApplier ──▶ SearchIndexWriter
                                                                                   │
                                                                          vortos_search_documents
                                                                                   │
   GET /api/search (app) ──▶ SearchQueryService ──▶ SearchReader ◀── SearchIndexDriver
                                   │                    │
                                 cache                scope (tenant + permission + owner)
```

### Scoping — two independent axes

| Axis | Column | Enforcement |
|------|--------|-------------|
| **Org** | `tenant_id` | `WHERE tenant_id = …` **and** Postgres row-level security (`search:pg:install --rls`). An org physically cannot read another org's rows. |
| **Member** | `permission` + `owner_member_id` | Org-shared rows need the caller's `permission` (or none); personal rows (`owner_member_id` set) are visible only to that member. |

A `SearchScope` (tenant + memberId + permissions, or `superuser`) is mandatory to read — there
is no unscoped query, and the cache key folds the scope in so it can never become a bypass.

### Database-agnostic driver

- **`PortableLikeSearchDriver`** (default) — case-insensitive `LIKE`, any SQL engine, no special index.
- **`PostgresFtsSearchDriver`** — weighted `tsvector` (`title` A, `subtitle`/`keywords` B, `body` C)
  + `word_similarity` trigram fuzzy fallback, ranked by `ts_rank_cd`. Enable with
  `->driver(SearchDriver::PostgresFts)` and run `vortos:search:pg:install`.

Scoping, pagination and deep-links are identical across drivers — switching a driver changes
relevance only, never who can see what. Implement `SearchIndexDriver` (+ writer/reader) to plug
an external engine like OpenSearch/Meilisearch.

## App integration (4 steps)

1. **Make a type searchable** — implement `SearchableProjection`; it's auto-discovered:
   ```php
   final class ApplicationSearchProjector implements SearchableProjection
   {
       public function subscribesTo(): array { return [ApplicationSubmitted::class, ApplicationDeleted::class]; }

       public function project(object $event): SearchUpsert|SearchDelete|null
       {
           if ($event instanceof ApplicationDeleted) {
               return new SearchDelete('application', $event->id, $event->tenantId);
           }
           return new SearchUpsert(new SearchDocument(
               type: 'application', entityId: $event->id, tenantId: $event->tenantId,
               title: $event->applicantName, subtitle: $event->status,
               deeplink: "/applications/{$event->leadEntryId}",
               permission: 'entries.view.any',
               keywords: [$event->email],
           ));
       }
   }
   ```
2. **Feed the bus** — one thin Kafka handler calls `$applier->apply($event)` on your indexing
   consumer group (owned by the app, so consumer/topic naming stays in app config).
3. **Serve queries** — a controller builds a `SearchScope` from the authenticated principal and
   calls `SearchQueryService::search()`.
4. **Backfill** — implement `SearchBackfillSourceInterface` per type; run `vortos:search:rebuild`.

## Configuration — `config/search.php` (all optional)

```php
use Vortos\Search\DependencyInjection\VortosSearchConfig;
use Vortos\Search\Enum\SearchDriver;

return static function (VortosSearchConfig $config): void {
    $config
        ->driver(SearchDriver::PostgresFts)   // default: Portable
        ->rowLevelSecurity(true)              // DB-enforced org isolation
        ->cacheTtl('15 seconds')              // hot-query cache (needs a Redis SearchCacheInterface)
        ->consumer('vortos.search');          // logical consumer the app's messaging config uses
};
```

## Commands

- `vortos:search:pg:install [--rls|--no-rls]` — Postgres extras (tsvector column + GIN + trigram + optional RLS). Idempotent.
- `vortos:search:rebuild [--type=…] [--tenant=…] [--fresh]` — (re)build the index from backfill sources.

## Metrics

Spread `SearchMetricDefinitions::all()` into your metrics registry (e.g. `config/metrics.php`):
`search_index_upsert_total`, `search_index_delete_total`, `search_query_total`,
`search_query_latency_seconds`.
