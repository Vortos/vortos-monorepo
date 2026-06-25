<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Runtime\IdentityDedupeCache;

final class IdentityDedupeCacheTest extends TestCase
{
    public function test_duplicate_identity_within_window_is_a_single_forward(): void
    {
        $cache = new IdentityDedupeCache();
        $identity = new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']);

        $this->assertFalse($cache->seenIdentity($identity), 'first call must forward');
        $this->assertTrue($cache->seenIdentity($identity), 'identical repeat must be a no-op');
    }

    public function test_distinct_identity_content_both_forward(): void
    {
        $cache = new IdentityDedupeCache();
        $a = new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']);
        $b = new IdentitySet(new DistinctId('user-1'), ['plan' => 'free']);

        $this->assertFalse($cache->seenIdentity($a));
        $this->assertFalse($cache->seenIdentity($b));
    }

    public function test_duplicate_group_within_window_is_a_single_forward(): void
    {
        $cache = new IdentityDedupeCache();
        $group = new GroupAssociation(new DistinctId('user-1'), 'org', 'acme');

        $this->assertFalse($cache->seenGroup($group));
        $this->assertTrue($cache->seenGroup($group));
    }

    public function test_lru_eviction_is_bounded(): void
    {
        $cache = new IdentityDedupeCache(maxEntries: 2);

        $first = new IdentitySet(new DistinctId('user-1'));
        $second = new IdentitySet(new DistinctId('user-2'));
        $third = new IdentitySet(new DistinctId('user-3'));

        $cache->seenIdentity($first);
        $cache->seenIdentity($second);
        $cache->seenIdentity($third); // evicts $first (oldest)

        $this->assertFalse($cache->seenIdentity($first), 'evicted entry must be treated as new again');
    }

    public function test_touching_an_entry_refreshes_its_lru_position(): void
    {
        $cache = new IdentityDedupeCache(maxEntries: 2);

        $first = new IdentitySet(new DistinctId('user-1'));
        $second = new IdentitySet(new DistinctId('user-2'));
        $third = new IdentitySet(new DistinctId('user-3'));

        $cache->seenIdentity($first);
        $cache->seenIdentity($second);
        $cache->seenIdentity($first); // touch -> $second becomes the oldest
        $cache->seenIdentity($third); // evicts $second, not $first

        $this->assertTrue($cache->seenIdentity($first), '$first was refreshed, must still be cached');
        $this->assertFalse($cache->seenIdentity($second), '$second was the least-recently-used, must be evicted');
    }
}
