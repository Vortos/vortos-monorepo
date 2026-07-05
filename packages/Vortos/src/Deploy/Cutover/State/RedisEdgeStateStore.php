<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\State;

/**
 * Control-plane {@see EdgeStateStoreInterface} backed by the framework's shared Redis (the same infra
 * used for outbox/DLQ idempotency). This is the default so a fleet of stateless edge nodes — across
 * hosts or regions — all reconstruct the same active-color route on boot by reading one central key,
 * rather than depending on any single host's local disk.
 *
 * Keyed per env (vortos:edge:state:<env>). The version is bumped with an atomic INCR on a sibling
 * key, giving a monotonic, race-free ordering of concurrent deploys; the payload is written with a
 * single SET (itself atomic). Holds only routing metadata — never secrets.
 */
final class RedisEdgeStateStore implements EdgeStateStoreInterface
{
    private const KEY_PREFIX = 'vortos:edge:state:';

    /**
     * @param \Redis|null $redis the shared ext-redis client; null when the app has not configured
     *                           Redis — in which case using this store fails closed with an
     *                           actionable message (set EDGE_STATE_STORE=file or configure Redis).
     */
    public function __construct(
        private readonly ?\Redis $redis = null,
    ) {}

    public function load(string $env): ?EdgeState
    {
        $redis = $this->requireRedis();
        $raw = $redis->get($this->key($env));
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return EdgeState::fromArray($decoded);
    }

    public function save(EdgeState $state): EdgeState
    {
        $redis = $this->requireRedis();

        $version = (int) $redis->incr($this->key($state->env) . ':version');
        $stamped = $state->withVersion($version, gmdate('c'));

        $json = json_encode($stamped->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $redis->set($this->key($state->env), $json);

        return $stamped;
    }

    private function requireRedis(): \Redis
    {
        if ($this->redis === null) {
            throw new \RuntimeException(
                'Edge state store is configured for Redis but no Redis client is available. '
                . 'Configure the framework Redis (vortos-cache DSN) or set EDGE_STATE_STORE=file.',
            );
        }

        return $this->redis;
    }

    private function key(string $env): string
    {
        return self::KEY_PREFIX . $env;
    }
}
