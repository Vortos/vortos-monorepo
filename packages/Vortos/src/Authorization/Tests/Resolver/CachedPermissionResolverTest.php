<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tests\Resolver;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Resolver\CachedPermissionResolver;
use Vortos\Authorization\Resolver\RoleGenerationStore;

/**
 * The cache must answer the question it was asked.
 *
 * The case that matters is one person holding different roles at different moments —
 * which is what re-minting a token for another organisation produces. Caching that on
 * the user alone handed the second token the first one's permissions.
 */
final class CachedPermissionResolverTest extends TestCase
{
    /** @var array<string, string> */
    private array $store = [];

    private int $innerCalls = 0;

    public function test_repeat_resolve_with_the_same_roles_is_served_from_cache(): void
    {
        $resolver = $this->resolver();
        $identity = $this->identity('user-1', ['admin']);

        $resolver->resolve($identity);
        $resolver->resolve($identity);

        self::assertSame(1, $this->innerCalls);
    }

    /**
     * The regression. Same user, different roles — as after switching organisation —
     * must not be answered from the entry cached for the previous role set.
     */
    public function test_a_different_role_set_is_not_served_the_previous_answer(): void
    {
        $resolver = $this->resolver();

        $asAdmin = $resolver->resolve($this->identity('user-1', ['admin']));
        self::assertTrue($asAdmin->has('org.manage'));

        $asViewer = $resolver->resolve($this->identity('user-1', ['viewer']));

        self::assertSame(2, $this->innerCalls, 'the viewer token must not reuse the admin entry');
        self::assertFalse($asViewer->has('org.manage'), 'viewer must not inherit admin permissions');
        self::assertTrue($asViewer->has('org.read'));
    }

    /** Role order is not meaning: the same set in another order is the same question. */
    public function test_role_order_does_not_split_the_cache(): void
    {
        $resolver = $this->resolver();

        $resolver->resolve($this->identity('user-1', ['admin', 'viewer']));
        $resolver->resolve($this->identity('user-1', ['viewer', 'admin']));

        self::assertSame(1, $this->innerCalls);
    }

    /** Two people are never each other's cache, whatever roles they hold. */
    public function test_different_users_do_not_share_an_entry(): void
    {
        $resolver = $this->resolver();

        $resolver->resolve($this->identity('user-1', ['admin']));
        $resolver->resolve($this->identity('user-2', ['admin']));

        self::assertSame(2, $this->innerCalls);
    }

    /**
     * Invalidation has to reach every role set the user was cached under, not just
     * whichever one happened to be written last.
     */
    public function test_invalidate_user_retires_every_role_set(): void
    {
        $resolver = $this->resolver();

        $resolver->resolve($this->identity('user-1', ['admin']));
        $resolver->resolve($this->identity('user-1', ['viewer']));
        self::assertSame(2, $this->innerCalls);

        $resolver->invalidateUser('user-1');

        $resolver->resolve($this->identity('user-1', ['admin']));
        $resolver->resolve($this->identity('user-1', ['viewer']));

        self::assertSame(4, $this->innerCalls, 'both entries should have been retired');
    }

    public function test_invalidating_one_user_leaves_another_alone(): void
    {
        $resolver = $this->resolver();

        $resolver->resolve($this->identity('user-1', ['admin']));
        $resolver->resolve($this->identity('user-2', ['admin']));

        $resolver->invalidateUser('user-1');
        $resolver->resolve($this->identity('user-2', ['admin']));

        self::assertSame(2, $this->innerCalls);
    }

    public function test_unauthenticated_identity_resolves_empty_without_consulting_the_inner(): void
    {
        $resolver = $this->resolver();

        $identity = $this->createMock(UserIdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(false);

        self::assertSame([], $resolver->resolve($identity)->permissions());
        self::assertSame(0, $this->innerCalls);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function resolver(): CachedPermissionResolver
    {
        $redis = $this->redis();

        return new CachedPermissionResolver($this->inner(), $redis, new RoleGenerationStore($redis));
    }

    /** Permissions that differ by role, so a wrong cache hit is visible in the result. */
    private function inner(): PermissionResolverInterface
    {
        $inner = $this->createMock(PermissionResolverInterface::class);
        $inner->method('resolve')->willReturnCallback(function (UserIdentityInterface $identity) {
            $this->innerCalls++;

            $roles = $identity->roles();
            $permissions = in_array('admin', $roles, true)
                ? ['org.read', 'org.manage']
                : ['org.read'];

            return new ResolvedPermissions($identity->id(), $roles, $roles, $permissions);
        });

        return $inner;
    }

    /** @param string[] $roles */
    private function identity(string $id, array $roles): UserIdentityInterface
    {
        $identity = $this->createMock(UserIdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn($id);
        $identity->method('roles')->willReturn($roles);

        return $identity;
    }

    /**
     * An in-memory stand-in for the handful of Redis operations this resolver uses.
     * Behavioural rather than a strict mock: what is under test is which key gets
     * read, which only shows up if the store actually remembers what was written.
     */
    private function redis(): \Redis
    {
        $redis = $this->createMock(\Redis::class);

        $redis->method('get')->willReturnCallback(
            fn (string $key): string|false => $this->store[$key] ?? false,
        );

        $redis->method('setEx')->willReturnCallback(
            function (string $key, int $ttl, mixed $value): bool {
                $this->store[$key] = (string) $value;
                return true;
            },
        );

        $redis->method('set')->willReturnCallback(
            function (string $key, mixed $value, mixed $options = null): bool {
                // Only used for the rebuild lock, which is always NX here.
                if (isset($this->store[$key])) {
                    return false;
                }
                $this->store[$key] = (string) $value;
                return true;
            },
        );

        $redis->method('del')->willReturnCallback(function (string ...$keys): int {
            foreach ($keys as $key) {
                unset($this->store[$key]);
            }
            return count($keys);
        });

        // RoleGenerationStore pipelines its reads; an empty exec() makes every role
        // generation 0, which is stable and therefore fine for these assertions.
        $redis->method('multi')->willReturnSelf();
        $redis->method('exec')->willReturn([]);

        $redis->method('eval')->willReturnCallback(
            fn (string $script, array $args = [], int $numKeys = 0): mixed => $this->runScript($script, $args),
        );

        return $redis;
    }

    /** @param array<int, mixed> $args */
    private function runScript(string $script, array $args): mixed
    {
        // Generation bump — invalidateUser.
        if (str_contains($script, 'incr')) {
            $key = (string) $args[0];
            $this->store[$key] = (string) (((int) ($this->store[$key] ?? 0)) + 1);

            return (int) $this->store[$key];
        }

        // Combined generation + entry read.
        if (str_contains($script, 'local generation')) {
            $generation = $this->store[(string) $args[0]] ?? '0';
            $entryKey = ((string) $args[1]) . $generation . ':' . ((string) $args[2]);

            return [$generation, $this->store[$entryKey] ?? false];
        }

        // Lock release — compare-and-delete.
        $lockKey = (string) $args[0];
        if (($this->store[$lockKey] ?? null) === (string) $args[1]) {
            unset($this->store[$lockKey]);
            return 1;
        }

        return 0;
    }
}
