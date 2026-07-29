<?php

declare(strict_types=1);

namespace Vortos\Authorization\Resolver;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Tracing\AuthorizationTracer;
use Vortos\Observability\Telemetry\TelemetryLabels;

/**
 * Caches resolved permissions in Redis, keyed by every input the answer depends on.
 *
 * The roles are part of the key, not just the user id. Resolution reads the roles the
 * identity presents (see DatabasePermissionResolver), so two tokens for the same
 * person carrying different roles are two different questions. Keying on the user
 * alone answered the second question with the first one's answer: a token re-minted
 * with another organisation's roles — which is what switching organisations does —
 * kept the previous organisation's permissions until the entry expired. That is a
 * tenancy boundary, so it is enforced by the key rather than by remembering to
 * invalidate at every site that re-mints a token.
 *
 * Invalidation is a generation counter rather than a delete, because one user now has
 * an entry per role set and there is no safe way to enumerate them (SCAN under load
 * is not one). Bumping the generation makes all of them unreachable at once; the
 * orphans expire on their own TTL.
 *
 * All keys for one user share a Redis Cluster hash tag, so the generation and its
 * entries live in the same slot and can be read in a single round trip.
 */
final class CachedPermissionResolver implements PermissionResolverInterface
{
    private const DEFAULT_TTL_SECONDS = 60;
    private const LOCK_TTL_MS = 3000;
    private const LOCK_RETRY_ATTEMPTS = 5;
    private const LOCK_RETRY_SLEEP_US = 60_000; // 60ms
    private const KEY_PREFIX = 'authorization:resolved_permissions:';

    /**
     * Must comfortably outlive an entry. If the generation expired while entries it
     * superseded were still alive, the counter would restart at zero and an
     * invalidated entry would become reachable again.
     */
    private const GENERATION_TTL_SECONDS = 86_400; // 24 hours

    public function __construct(
        private readonly PermissionResolverInterface $inner,
        private readonly \Redis $redis,
        private readonly RoleGenerationStore $generations,
        private readonly ?AuthorizationTracer $tracer = null,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    public function resolve(UserIdentityInterface $identity): ResolvedPermissions
    {
        if (!$identity->isAuthenticated()) {
            return ResolvedPermissions::empty();
        }

        $fingerprint = $this->fingerprint($identity);
        [$generation, $cached] = $this->readCurrent($identity->id(), $fingerprint);

        if ($cached !== null && $this->isFresh($cached)) {
            $span = $this->tracer?->resolver('authorization.resolver.cache_hit', [
                'authorization.user_id_hash' => TelemetryLabels::userHash($identity->id()),
            ]);
            $span?->setStatus('ok');
            $span?->end();

            return ResolvedPermissions::fromArray($cached['resolved']);
        }

        $span = $this->tracer?->resolver('authorization.resolver.cache_miss', [
            'authorization.user_id_hash' => TelemetryLabels::userHash($identity->id()),
        ]);

        try {
            $resolved = $this->rebuildWithLock(
                $identity,
                $this->entryKey($identity->id(), $generation, $fingerprint),
            );
            $span?->setStatus('ok');
            return $resolved;
        } catch (\Throwable $e) {
            $span?->recordException($e);
            $span?->setStatus('error');
            throw $e;
        } finally {
            $span?->end();
        }
    }

    public function has(UserIdentityInterface $identity, string $permission): bool
    {
        return $this->resolve($identity)->has($permission);
    }

    /**
     * Retires every cached answer for this user, across all role sets.
     *
     * Deliberately not a delete: the entries are spread across one key per role set,
     * and the generation is the only handle that reaches all of them atomically.
     */
    public function invalidateUser(string $userId): void
    {
        $script = <<<'LUA'
local v = redis.call("incr", KEYS[1])
redis.call("expire", KEYS[1], ARGV[1])
return v
LUA;

        $this->redis->eval($script, [$this->generationKey($userId), self::GENERATION_TTL_SECONDS], 1);
    }

    /**
     * The identity inputs the resolved answer depends on.
     *
     * Only the roles: the tenant is deliberately absent because resolution does not
     * read it — role grants are global, and anything organisation-specific is decided
     * later by scope and ownership. Including it would multiply cache entries without
     * changing a single answer.
     *
     * @param UserIdentityInterface $identity
     */
    private function fingerprint(UserIdentityInterface $identity): string
    {
        $roles = array_values(array_unique(array_filter($identity->roles(), 'is_string')));
        sort($roles);

        return hash('sha256', json_encode($roles, JSON_THROW_ON_ERROR));
    }

    /**
     * Reads the current generation and this fingerprint's entry together.
     *
     * One round trip rather than two: the entry key cannot be built until the
     * generation is known, so the lookup happens inside Redis. The entry key is not
     * declared in KEYS, which is safe here only because the hash tag puts it in the
     * same slot as the generation — see the class docblock.
     *
     * @return array{0: int, 1: array{resolved: array<string, mixed>, roleGenerationHash: string}|null}
     */
    private function readCurrent(string $userId, string $fingerprint): array
    {
        $script = <<<'LUA'
local generation = redis.call("get", KEYS[1])
if generation == false then generation = "0" end
return {generation, redis.call("get", ARGV[1] .. generation .. ":" .. ARGV[2])}
LUA;

        /** @var array{0: mixed, 1: mixed}|false $result */
        $result = $this->redis->eval(
            $script,
            [
                $this->generationKey($userId),
                self::KEY_PREFIX . $this->userSlot($userId) . ':',
                $fingerprint,
            ],
            1,
        );

        if (!is_array($result)) {
            return [0, null];
        }

        return [(int) ($result[0] ?? 0), $this->decode($result[1] ?? null)];
    }

    /**
     * @return array{resolved: array<string, mixed>, roleGenerationHash: string}|null
     */
    private function decode(mixed $payload): ?array
    {
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) && isset($data['resolved'], $data['roleGenerationHash'])
            ? $data
            : null;
    }

    /**
     * @return array{resolved: array<string, mixed>, roleGenerationHash: string}|null
     */
    private function read(string $cacheKey): ?array
    {
        $payload = $this->redis->get($cacheKey);

        return $payload === false ? null : $this->decode($payload);
    }

    /**
     * @param array{resolved: array<string, mixed>, roleGenerationHash: string} $cached
     */
    private function isFresh(array $cached): bool
    {
        $roles = $cached['resolved']['expandedRoles'] ?? [];

        if (!is_array($roles)) {
            return false;
        }

        return hash_equals(
            (string) $cached['roleGenerationHash'],
            $this->generations->hashForRoles($roles),
        );
    }

    private function rebuildWithLock(UserIdentityInterface $identity, string $cacheKey): ResolvedPermissions
    {
        $lockKey = $cacheKey . ':lock';
        $token = bin2hex(random_bytes(12));

        if ($this->acquireLock($lockKey, $token)) {
            try {
                $resolved = $this->inner->resolve($identity);
                $this->write($cacheKey, $resolved);
                return $resolved;
            } finally {
                $this->releaseLock($lockKey, $token);
            }
        }

        // Lock held by another worker — retry with backoff before falling back to direct resolve
        for ($i = 0; $i < self::LOCK_RETRY_ATTEMPTS; $i++) {
            usleep(self::LOCK_RETRY_SLEEP_US * (1 + $i));

            $cached = $this->read($cacheKey);
            if ($cached !== null && $this->isFresh($cached)) {
                return ResolvedPermissions::fromArray($cached['resolved']);
            }
        }

        // All retries exhausted — resolve directly without caching to avoid a thundering herd write
        return $this->inner->resolve($identity);
    }

    private function write(string $cacheKey, ResolvedPermissions $resolved): void
    {
        $payload = [
            'resolved' => $resolved->toArray(),
            'roleGenerationHash' => $this->generations->hashForRoles($resolved->expandedRoles()),
        ];

        $this->redis->setEx(
            $cacheKey,
            $this->ttlSeconds,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function acquireLock(string $lockKey, string $token): bool
    {
        return (bool) $this->redis->set($lockKey, $token, ['nx', 'px' => self::LOCK_TTL_MS]);
    }

    private function releaseLock(string $lockKey, string $token): void
    {
        $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
end
return 0
LUA;

        $this->redis->eval($script, [$lockKey, $token], 1);
    }

    /**
     * Redis Cluster hash tag. Every key belonging to one user hashes to one slot, so
     * the generation and the entries it governs can be read in a single script.
     */
    private function userSlot(string $userId): string
    {
        return '{' . hash('sha256', $userId) . '}';
    }

    private function generationKey(string $userId): string
    {
        return self::KEY_PREFIX . $this->userSlot($userId) . ':gen';
    }

    private function entryKey(string $userId, int $generation, string $fingerprint): string
    {
        return self::KEY_PREFIX . $this->userSlot($userId) . ':' . $generation . ':' . $fingerprint;
    }
}
