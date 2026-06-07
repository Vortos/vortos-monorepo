<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Exception\InvalidObjectKeyException;
use Vortos\ObjectStore\ValueObject\ObjectKey;

final class ObjectKeyTest extends TestCase
{
    public function test_normalizes_slashes_and_leading_slash(): void
    {
        $key = new ObjectKey('/tmp\\uploads//video.mp4');

        $this->assertSame('tmp/uploads/video.mp4', $key->value());
    }

    public function test_rejects_empty_key(): void
    {
        $this->expectException(InvalidObjectKeyException::class);
        new ObjectKey(' / ');
    }

    public function test_rejects_parent_directory_segments(): void
    {
        $this->expectException(InvalidObjectKeyException::class);
        new ObjectKey('tmp/../secret.txt');
    }

    public function test_adds_prefix_without_duplicate_slashes(): void
    {
        $key = (new ObjectKey('video.mp4'))->withPrefix('/tenant-a/uploads/');

        $this->assertSame('tenant-a/uploads/video.mp4', $key->value());
    }
}
