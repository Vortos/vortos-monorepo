<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Event;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Event\GroupAssociation;

final class GroupAssociationTest extends TestCase
{
    public function test_constructs_with_valid_fields(): void
    {
        $group = new GroupAssociation(new DistinctId('user-1'), 'org', 'acme', ['seats' => 10]);

        $this->assertSame('org', $group->groupType);
        $this->assertSame('acme', $group->groupKey);
        $this->assertSame(['seats' => 10], $group->traits);
    }

    public function test_rejects_empty_group_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GroupAssociation(new DistinctId('user-1'), '', 'acme');
    }

    public function test_rejects_empty_group_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GroupAssociation(new DistinctId('user-1'), 'org', '');
    }

    public function test_bounds_traits(): void
    {
        $huge = [];
        for ($i = 0; $i < 1000; $i++) {
            $huge['t' . $i] = $i;
        }

        $group = new GroupAssociation(new DistinctId('user-1'), 'org', 'acme', $huge);
        $this->assertLessThanOrEqual(GroupAssociation::MAX_TRAITS, count($group->traits));
    }

    public function test_content_hash_is_deterministic_and_distinguishes_group_key(): void
    {
        $a = new GroupAssociation(new DistinctId('user-1'), 'org', 'acme');
        $b = new GroupAssociation(new DistinctId('user-1'), 'org', 'acme');
        $c = new GroupAssociation(new DistinctId('user-1'), 'org', 'other-co');

        $this->assertSame($a->contentHash(), $b->contentHash());
        $this->assertNotSame($a->contentHash(), $c->contentHash());
    }
}
