<?php

declare(strict_types=1);

namespace Vortos\Tests\ObjectStore\Testing;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Exception\ObjectNotFoundException;
use Vortos\ObjectStore\Testing\ObjectStoreFake;
use Vortos\ObjectStore\ValueObject\ByteRange;
use Vortos\ObjectStore\ValueObject\GetObjectOptions;

final class ObjectStoreFakeTest extends TestCase
{
    public function test_stores_reads_lists_and_deletes_objects(): void
    {
        $store = new ObjectStoreFake();
        $store->put('registrations/a.txt', 'hello');

        $store->assertExists('registrations/a.txt');
        $store->assertContents('registrations/a.txt', 'hello');
        $this->assertCount(1, $store->list()->objects());

        $store->delete('registrations/a.txt');
        $store->assertMissing('registrations/a.txt');
    }

    public function test_range_reads_are_supported(): void
    {
        $store = new ObjectStoreFake();
        $store->put('video.mp4', 'abcdef');

        $this->assertSame('bcd', $store->get('video.mp4', new GetObjectOptions(new ByteRange(1, 3)))->contents());
    }

    public function test_missing_object_throws_domain_exception(): void
    {
        $this->expectException(ObjectNotFoundException::class);
        (new ObjectStoreFake())->get('missing.txt');
    }
}
