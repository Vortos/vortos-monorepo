<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\TokenFreshness;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\TokenFreshness\MinIatGuard;
use Vortos\Auth\TokenFreshness\Storage\InMemoryMinIatStore;

final class MinIatGuardTest extends TestCase
{
    public function test_returns_null_when_no_min_iat_set(): void
    {
        $guard = new MinIatGuard(new InMemoryMinIatStore());

        $this->assertNull($guard->check('user-1', 0, time()));
    }

    public function test_returns_null_when_token_issued_after_min_iat(): void
    {
        $store = new InMemoryMinIatStore();
        $store->set(1000);
        $guard = new MinIatGuard($store);

        $this->assertNull($guard->check('user-1', 0, 2000));
    }

    public function test_returns_null_when_token_issued_at_exactly_min_iat(): void
    {
        $store = new InMemoryMinIatStore();
        $store->set(1000);
        $guard = new MinIatGuard($store);

        $this->assertNull($guard->check('user-1', 0, 1000));
    }

    public function test_rejects_token_issued_before_min_iat(): void
    {
        $store = new InMemoryMinIatStore();
        $store->set(2000);
        $guard = new MinIatGuard($store);

        $reason = $guard->check('user-1', 0, 1000);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('revocation', $reason);
    }

    public function test_rejects_on_store_failure(): void
    {
        $store = new class implements \Vortos\Auth\TokenFreshness\MinIatStoreInterface {
            public function get(): ?int { throw new \RuntimeException('Redis down'); }
            public function set(int $epoch): void {}
        };
        $guard = new MinIatGuard($store);

        $reason = $guard->check('user-1', 0, time());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('unavailable', $reason);
    }
}
