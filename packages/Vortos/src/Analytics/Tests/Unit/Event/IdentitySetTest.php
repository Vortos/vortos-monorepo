<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Event\IdentitySet;

final class IdentitySetTest extends TestCase
{
    public function test_bounds_traits(): void
    {
        $huge = [];
        for ($i = 0; $i < 1000; $i++) {
            $huge['t' . $i] = $i;
        }

        $identity = new IdentitySet(new DistinctId('user-1'), $huge);
        $this->assertLessThanOrEqual(IdentitySet::MAX_TRAITS, count($identity->traits));
    }

    public function test_content_hash_is_deterministic(): void
    {
        $a = new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']);
        $b = new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']);

        $this->assertSame($a->contentHash(), $b->contentHash());
    }

    public function test_content_hash_differs_for_different_traits(): void
    {
        $a = new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']);
        $b = new IdentitySet(new DistinctId('user-1'), ['plan' => 'free']);

        $this->assertNotSame($a->contentHash(), $b->contentHash());
    }

    public function test_content_hash_differs_for_different_distinct_id(): void
    {
        $a = new IdentitySet(new DistinctId('user-1'), ['plan' => 'pro']);
        $b = new IdentitySet(new DistinctId('user-2'), ['plan' => 'pro']);

        $this->assertNotSame($a->contentHash(), $b->contentHash());
    }
}
