<?php

declare(strict_types=1);

namespace Vortos\Search\DependencyInjection;

use Vortos\Search\Enum\SearchDriver;

/**
 * Fluent configuration for vortos-search.
 *
 * Loaded via `(require config/search.php)($config)` in {@see SearchExtension::loadConfig()},
 * matching the Audit/Scheduler/Messaging convention. Every knob has a sensible default — no
 * config file is required for basic (portable-driver) usage.
 *
 * ## Standard usage — config/search.php
 *
 *   return static function (VortosSearchConfig $config): void {
 *       $config
 *           ->driver(SearchDriver::PostgresFts)   // opt into Postgres full-text + trigram
 *           ->rowLevelSecurity(true)              // DB-enforced org isolation of the index
 *           ->cacheTtl('15 seconds')              // hot-query cache window (needs a Redis cache service)
 *           ->consumer('vortos.search');          // logical consumer the app's messaging config uses
 *   };
 *
 * Env-specific overrides go in config/{env}/search.php, loaded after the base file.
 */
final class VortosSearchConfig
{
    private SearchDriver $driver;
    private bool $rowLevelSecurity;
    private int $cacheTtlSeconds;
    private string $consumer;

    public function __construct()
    {
        $this->driver           = SearchDriver::Portable;
        $this->rowLevelSecurity = false;
        $this->cacheTtlSeconds  = 15;
        $this->consumer         = 'vortos.search';
    }

    /** Matching/ranking driver. Portable (any DB, default) or Postgres full-text + trigram. */
    public function driver(SearchDriver $driver): static
    {
        $this->driver = $driver;
        return $this;
    }

    /**
     * Enable Postgres row-level security on search_documents as a DB-enforced tenant-isolation
     * backstop. No-op off Postgres. Applied by `vortos:search:pg:install`; the app must set the
     * per-request `app.current_tenant` GUC.
     */
    public function rowLevelSecurity(bool $enabled = true): static
    {
        $this->rowLevelSecurity = $enabled;
        return $this;
    }

    /**
     * Hot-query cache window. Accepts a human duration ('15 seconds') or bare seconds. 0 disables
     * caching. Only effective when the app wires a {@see \Vortos\Search\Cache\SearchCacheInterface}
     * (e.g. Redis); the default is a no-op cache.
     */
    public function cacheTtl(string|int $duration): static
    {
        $this->cacheTtlSeconds = self::toSeconds($duration);
        return $this;
    }

    /** Logical consumer/topic name the app's messaging config uses for the indexing pipeline. */
    public function consumer(string $name): static
    {
        $this->consumer = $name;
        return $this;
    }

    /** @return array<string, mixed> @internal consumed by SearchExtension */
    public function toArray(): array
    {
        return [
            'driver'             => $this->driver->value,
            'row_level_security' => $this->rowLevelSecurity,
            'cache_ttl_seconds'  => $this->cacheTtlSeconds,
            'consumer'           => $this->consumer,
        ];
    }

    private static function toSeconds(string|int $duration): int
    {
        if (is_int($duration)) {
            return max(0, $duration);
        }
        if (ctype_digit($duration)) {
            return max(0, (int) $duration);
        }
        $seconds = strtotime($duration, 0);

        return $seconds !== false && $seconds > 0 ? $seconds : 15;
    }
}
