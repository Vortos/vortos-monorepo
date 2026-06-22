<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Resolution;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Resolution\EnvironmentScopedFlagResolver;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Unit tests for EnvironmentScopedFlagResolver (Block 10).
 *
 * Critical coverage:
 *   - Cross-environment isolation: dev key cannot see prod state (headline security test).
 *   - N+1 guard: exactly 2 storage calls per request regardless of flag count.
 *   - Back-compat: legacy flags (no env state row) resolve in production; invisible elsewhere.
 *   - Memoization: second resolve() call in same env does NOT re-query storage.
 *   - reset() clears memo so new request starts fresh.
 */
final class EnvironmentScopedFlagResolverTest extends TestCase
{
    private const FLAG_ID   = '11111111-1111-4111-8111-111111111111';
    private const FLAG_ID_2 = '22222222-2222-4222-8222-222222222222';

    public function test_resolves_flag_for_active_env(): void
    {
        $scope    = new FlagScopeContext();
        $scope->withEnvironment('production');

        $storage  = new SpyFlagStorage([self::FLAG_ID => $this->makeDefinition('my-flag')]);
        $envStore = new SpyEnvStateStorage(['production' => [self::FLAG_ID => $this->makeEnvState(self::FLAG_ID, 'production', true)]]);

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);
        $flag     = $resolver->resolve('my-flag', new FlagContext('u1'));

        $this->assertNotNull($flag);
        $this->assertTrue($flag->enabled);
        $this->assertSame('production', $flag->environment);
    }

    public function test_cross_env_isolation_dev_cannot_see_prod_state(): void
    {
        // prod: flag enabled; dev: flag disabled.
        $scope = new FlagScopeContext();
        $scope->withEnvironment('dev');

        $storage = new SpyFlagStorage([self::FLAG_ID => $this->makeDefinition('secret-flag')]);
        $envStore = new SpyEnvStateStorage([
            'production' => [self::FLAG_ID => $this->makeEnvState(self::FLAG_ID, 'production', true)],
            'dev'        => [self::FLAG_ID => $this->makeEnvState(self::FLAG_ID, 'dev', false)],
        ]);

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);
        $flag     = $resolver->resolve('secret-flag', new FlagContext('u1'));

        $this->assertNotNull($flag);
        $this->assertFalse($flag->enabled, 'dev scope must NOT see production enabled state');
        $this->assertSame('dev', $flag->environment);
    }

    public function test_flag_not_configured_for_env_is_invisible(): void
    {
        $scope = new FlagScopeContext();
        $scope->withEnvironment('canary');

        $storage  = new SpyFlagStorage([self::FLAG_ID => $this->makeDefinition('my-flag')]);
        // No env state for 'canary' — flag should be invisible (not resolved).
        $envStore = new SpyEnvStateStorage(['production' => [self::FLAG_ID => $this->makeEnvState(self::FLAG_ID, 'production', true)]]);

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);
        $flag     = $resolver->resolve('my-flag', new FlagContext('u1'));

        $this->assertNull($flag, 'flag not configured in canary env must be invisible');
    }

    public function test_back_compat_legacy_flag_resolves_in_production(): void
    {
        $scope = new FlagScopeContext();
        $scope->withEnvironment('production');

        // Legacy flag: has no env state row.
        $storage  = new SpyFlagStorage([self::FLAG_ID => $this->makeDefinition('legacy-flag', enabled: true)]);
        $envStore = new SpyEnvStateStorage([]); // no env states at all

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);
        $flag     = $resolver->resolve('legacy-flag', new FlagContext('u1'));

        $this->assertNotNull($flag, 'legacy flag must resolve in production even without env state');
        $this->assertTrue($flag->enabled);
    }

    public function test_back_compat_legacy_flag_invisible_in_non_production(): void
    {
        $scope = new FlagScopeContext();
        $scope->withEnvironment('staging');

        $storage  = new SpyFlagStorage([self::FLAG_ID => $this->makeDefinition('legacy-flag', enabled: true)]);
        $envStore = new SpyEnvStateStorage([]);

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);
        $flag     = $resolver->resolve('legacy-flag', new FlagContext('u1'));

        $this->assertNull($flag, 'legacy flag must be invisible in non-production without env state');
    }

    public function test_n_plus_one_guard_exactly_two_queries(): void
    {
        $scope = new FlagScopeContext();
        $scope->withEnvironment('production');

        $definitions = [];
        for ($i = 1; $i <= 100; $i++) {
            $definitions["flag-id-$i"] = $this->makeDefinition("flag-$i");
        }

        $storage  = new SpyFlagStorage($definitions);
        $envStore = new SpyEnvStateStorage([]);

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);

        // Resolve 10 different flags.
        for ($i = 1; $i <= 10; $i++) {
            $resolver->resolve("flag-$i", new FlagContext('u1'));
        }

        $this->assertSame(1, $storage->findAllCalls, 'definitions must be fetched exactly once');
        $this->assertSame(1, $envStore->findAllForEnvCalls, 'env states must be fetched exactly once');
    }

    public function test_memoization_within_same_env(): void
    {
        $scope = new FlagScopeContext();
        $scope->withEnvironment('production');

        $storage  = new SpyFlagStorage([self::FLAG_ID => $this->makeDefinition('my-flag')]);
        $envStore = new SpyEnvStateStorage([]);

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);

        $resolver->resolve('my-flag', new FlagContext('u1'));
        $resolver->resolve('my-flag', new FlagContext('u2'));
        $resolver->resolve('my-flag', new FlagContext('u3'));

        $this->assertSame(1, $storage->findAllCalls, 'storage must be called only once (memoized)');
    }

    public function test_memo_is_invalidated_when_env_changes(): void
    {
        $scope = new FlagScopeContext();
        $scope->withEnvironment('production');

        $storage  = new SpyFlagStorage([self::FLAG_ID => $this->makeDefinition('my-flag')]);
        $envStore = new SpyEnvStateStorage([]);

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);
        $resolver->resolve('my-flag', new FlagContext('u1')); // primes memo for 'production'

        $scope->withEnvironment('staging');
        $resolver->resolve('my-flag', new FlagContext('u1')); // must re-query for 'staging'

        $this->assertSame(2, $storage->findAllCalls, 'storage must be called again for new env');
    }

    public function test_reset_clears_memo(): void
    {
        $scope = new FlagScopeContext();
        $scope->withEnvironment('production');

        $storage  = new SpyFlagStorage([self::FLAG_ID => $this->makeDefinition('my-flag')]);
        $envStore = new SpyEnvStateStorage([]);

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);
        $resolver->resolve('my-flag', new FlagContext('u1')); // primes memo

        $resolver->reset();
        $resolver->resolve('my-flag', new FlagContext('u1')); // should re-query after reset

        $this->assertSame(2, $storage->findAllCalls, 'memo must be cleared after reset()');
    }

    public function test_resolve_all_returns_all_env_flags(): void
    {
        $scope = new FlagScopeContext();
        $scope->withEnvironment('staging');

        $storage = new SpyFlagStorage([
            self::FLAG_ID   => $this->makeDefinition('flag-a'),
            self::FLAG_ID_2 => $this->makeDefinition('flag-b'),
        ]);
        $envStore = new SpyEnvStateStorage([
            'staging' => [
                self::FLAG_ID   => $this->makeEnvState(self::FLAG_ID, 'staging', true),
                self::FLAG_ID_2 => $this->makeEnvState(self::FLAG_ID_2, 'staging', false),
            ],
        ]);

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);
        $flags    = $resolver->resolveAll(new FlagContext('u1'));

        $this->assertCount(2, $flags);
        $names = array_map(fn(FeatureFlag $f) => $f->name, $flags);
        sort($names);
        $this->assertSame(['flag-a', 'flag-b'], $names);
    }

    public function test_compose_merges_definition_and_state(): void
    {
        $scope = new FlagScopeContext();
        $scope->withEnvironment('dev');

        $storage  = new SpyFlagStorage([self::FLAG_ID => $this->makeDefinition('my-flag', enabled: false)]);
        $envStore = new SpyEnvStateStorage([
            'dev' => [self::FLAG_ID => $this->makeEnvState(self::FLAG_ID, 'dev', true)],
        ]);

        $resolver = new EnvironmentScopedFlagResolver($storage, $envStore, $scope);
        $flag     = $resolver->resolve('my-flag', new FlagContext('u1'));

        $this->assertNotNull($flag);
        $this->assertTrue($flag->enabled, 'env state (enabled=true) overrides definition (enabled=false)');
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function makeDefinition(string $name, bool $enabled = false): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag(
            id:          self::FLAG_ID,
            name:        $name,
            description: '',
            enabled:     $enabled,
            rules:       [],
            variants:    null,
            createdAt:   $now,
            updatedAt:   $now,
        );
    }

    private function makeEnvState(string $flagId, string $environment, bool $enabled): FlagEnvironmentState
    {
        return new FlagEnvironmentState(
            flagId:        $flagId,
            environment:   $environment,
            enabled:       $enabled,
            rules:         [],
            variants:      null,
            variantRules:  null,
            schedule:      null,
            payload:       null,
            requiredScope: null,
            prerequisites: [],
            updatedAt:     new \DateTimeImmutable(),
        );
    }
}

// ─── Test doubles ────────────────────────────────────────────────────────────

/** @internal */
final class SpyFlagStorage implements FlagStorageInterface
{
    public int $findAllCalls = 0;

    /** @param array<string, FeatureFlag> $flagsById */
    public function __construct(private readonly array $flagsById) {}

    public function findAll(): array
    {
        $this->findAllCalls++;
        return array_values($this->flagsById);
    }

    public function findByName(string $name): ?FeatureFlag
    {
        foreach ($this->flagsById as $flag) {
            if ($flag->name === $name) return $flag;
        }
        return null;
    }

    public function findById(string $id): ?FeatureFlag
    {
        return $this->flagsById[$id] ?? null;
    }

    public function save(FeatureFlag $flag): void {}

    public function delete(string $name): void {}
}

/** @internal */
final class SpyEnvStateStorage implements FlagEnvironmentStateStorageInterface
{
    public int $findAllForEnvCalls = 0;

    /**
     * @param array<string, array<string, FlagEnvironmentState>> $statesByEnv keyed by env then flagId
     */
    public function __construct(private readonly array $statesByEnv) {}

    public function findAllForEnv(string $environment): array
    {
        $this->findAllForEnvCalls++;
        return $this->statesByEnv[$environment] ?? [];
    }

    public function findForFlag(string $flagId, string $environment): ?FlagEnvironmentState
    {
        return ($this->statesByEnv[$environment] ?? [])[$flagId] ?? null;
    }

    public function save(FlagEnvironmentState $state): void {}

    public function delete(string $flagId, string $environment): void {}
}
