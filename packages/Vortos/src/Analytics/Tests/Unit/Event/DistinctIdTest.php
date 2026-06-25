<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Event;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\DistinctId;

final class DistinctIdTest extends TestCase
{
    public function test_accepts_a_valid_id(): void
    {
        $id = new DistinctId('user-1');
        $this->assertSame('user-1', $id->value);
        $this->assertSame('user-1', (string) $id);
    }

    public function test_rejects_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DistinctId('');
    }

    public function test_accepts_id_at_max_length(): void
    {
        $id = new DistinctId(str_repeat('a', DistinctId::MAX_LENGTH));
        $this->assertSame(DistinctId::MAX_LENGTH, strlen($id->value));
    }

    public function test_rejects_id_over_max_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DistinctId(str_repeat('a', DistinctId::MAX_LENGTH + 1));
    }

    public function test_equals_compares_by_value(): void
    {
        $a = new DistinctId('user-1');
        $b = new DistinctId('user-1');
        $c = new DistinctId('user-2');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
