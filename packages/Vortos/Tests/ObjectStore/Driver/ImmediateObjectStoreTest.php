<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Driver\ImmediateObjectStore;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\ObjectKey;

final class ImmediateObjectStoreTest extends TestCase
{
    public function test_delegates_directly_to_inner_store(): void
    {
        $result = new DeleteResult(new ObjectKey('tmp/a.txt'), true);
        $inner = $this->createMock(ObjectStoreInterface::class);
        $inner->expects($this->once())->method('delete')->with('tmp/a.txt')->willReturn($result);

        $this->assertSame($result, (new ImmediateObjectStore($inner))->delete('tmp/a.txt'));
    }
}
