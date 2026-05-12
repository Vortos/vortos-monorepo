<?php

declare(strict_types=1);

namespace Vortos\Authorization\Resolver;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Tracing\AuthorizationTracer;
use Vortos\Observability\Telemetry\TelemetryLabels;

final class CachedPermissionResolver implements PermissionResolverInterface
{
    private const DEFAULT_TTL_SECONDS = 60;
    private const LOCK_TTL_MS = 3000;
    private const LOCK_RETRY_ATTEMPTS = 5;
    private const LOCK_RETRY_SLEEP_US = 60_000; // 60ms

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

        $cacheKey = $this->cacheKey($identity->id());
        $cached = $this->read($cacheKey);

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
            $resolved = $this->rebuildWithLock($identity, $cacheKey);
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

    public function invalidateUser(string $userId): void
    {
        $this->redis->del($this->cacheKey($userId));
    }

    /**
     * @return array{resolved: array<string, mixed>, roleGenerationHash: string}|null
     */
    private function read(string $cacheKey): ?array
    {
        $payload = $this->redis->get($cacheKey);

        if ($payload === false || !is_string($payload)) {
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

    private function cacheKey(string $userId): string
    {
        return 'authorization:resolved_permissions:' . hash('sha256', $userId);
    }
}
