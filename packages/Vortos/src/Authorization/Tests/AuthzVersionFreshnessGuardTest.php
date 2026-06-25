<?php
declare(strict_types=1);

namespace Vortos\Authorization\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Identity\AuthzVersionFreshnessGuard;

final class AuthzVersionFreshnessGuardTest extends TestCase
{
    public function test_returns_null_when_version_is_current(): void
    {
        $store = $this->storeReturning(5);
        $guard = new AuthzVersionFreshnessGuard($store);

        $this->assertNull($guard->check('user-1', 5, time()));
    }

    public function test_returns_null_when_version_is_ahead(): void
    {
        $store = $this->storeReturning(3);
        $guard = new AuthzVersionFreshnessGuard($store);

        $this->assertNull($guard->check('user-1', 5, time()));
    }

    public function test_rejects_stale_version(): void
    {
        $store = $this->storeReturning(10);
        $guard = new AuthzVersionFreshnessGuard($store);

        $reason = $guard->check('user-1', 5, time());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('stale', $reason);
    }

    public function test_version_zero_passes_when_store_returns_zero(): void
    {
        $store = $this->storeReturning(0);
        $guard = new AuthzVersionFreshnessGuard($store);

        $this->assertNull($guard->check('user-1', 0, time()));
    }

    private function storeReturning(int $version): AuthorizationVersionStoreInterface
    {
        return new class($version) implements AuthorizationVersionStoreInterface {
            public function __construct(private int $v) {}
            public function versionForUser(string $userId): int { return $this->v; }
            public function increment(string $userId): int { return ++$this->v; }
        };
    }
}
