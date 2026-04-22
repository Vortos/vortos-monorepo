<?php

declare(strict_types=1);

namespace Vortos\Cache\Adapter;

/**
 * Builds a connected \Redis instance from a DSN string.
 *
 * Pure static factory — no constructor, no state.
 * Connection is established immediately on construction — not lazy.
 * If Redis is unreachable, this throws at container boot time.
 *
 * ## DSN format
 *
 *   redis://host:port
 *   redis://:password@host:port
 *   redis://:password@host:port/database
 *
 * Examples:
 *   redis://redis:6379
 *   redis://:secretpassword@redis:6379
 *   redis://:secretpassword@redis:6379/1
 *
 * ## Why ext-redis not Predis
 *
 * ext-redis is a C extension — 3-5x faster than Predis (pure PHP userland).
 * For a cache called dozens of times per request, this difference compounds.
 * ext-redis is already installed in the Vortos Docker image via pecl install redis.
 *
 * ## Database selection
 *
 * Redis supports 16 logical databases (0-15) on a single instance.
 * Use database 0 (default) for application cache.
 * Use a different database index if you need logical separation on a shared
 * Redis instance — though key prefixes are usually sufficient.
 */
final class RedisConnectionFactory
{
    private function __construct() {}

    /**
     * Build and return a connected \Redis instance.
     *
     * @param string $dsn DSN string — redis://[:password@]host[:port][/database]
     *
     * @throws \InvalidArgumentException If the DSN is malformed
     * @throws \RuntimeException         If connection or authentication fails
     */
    public static function fromDsn(string $dsn): \Redis
    {
        $parsed = parse_url($dsn);

        if ($parsed === false || empty($parsed['host'])) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Redis DSN "%s". Expected format: redis://[:password@]host[:port][/database]',
                $dsn,
            ));
        }

        $host     = $parsed['host'];
        $port     = $parsed['port'] ?? 6379;
        $password = isset($parsed['pass']) && $parsed['pass'] !== '' ? $parsed['pass'] : null;
        $database = isset($parsed['path']) && $parsed['path'] !== '/'
            ? (int) ltrim($parsed['path'], '/')
            : 0;

        $redis = new \Redis();

        try {
            $redis->connect($host, $port);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Failed to connect to Redis at "%s:%d": %s',
                $host,
                $port,
                $e->getMessage(),
            ), 0, $e);
        }

        if ($password !== null) {
            if (!$redis->auth($password)) {
                throw new \RuntimeException(sprintf(
                    'Redis authentication failed for host "%s:%d".',
                    $host,
                    $port,
                ));
            }
        }

        if ($database !== 0) {
            $redis->select($database);
        }

        // Disable built-in serialization — RedisAdapter handles serialization directly
        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);

        return $redis;
    }
}
