<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Registry\ImageReference;

final class ImageReferenceTest extends TestCase
{
    public function test_basic_construction(): void
    {
        $ref = new ImageReference('myrepo/myimage', 'v1.0', 'sha256:' . str_repeat('a', 64));

        self::assertSame('myrepo/myimage', $ref->repository);
        self::assertSame('v1.0', $ref->tag);
        self::assertTrue($ref->isDigestPinned());
    }

    public function test_without_digest(): void
    {
        $ref = new ImageReference('myrepo/myimage', 'latest');
        self::assertFalse($ref->isDigestPinned());
    }

    public function test_empty_repository_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImageReference('');
    }

    public function test_invalid_digest_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImageReference('repo', null, 'bad-digest');
    }

    public function test_with_digest(): void
    {
        $ref = new ImageReference('repo', 'v1');
        $pinned = $ref->withDigest('sha256:' . str_repeat('b', 64));

        self::assertTrue($pinned->isDigestPinned());
        self::assertSame('v1', $pinned->tag);
    }

    public function test_with_tag(): void
    {
        $ref = new ImageReference('repo', 'old-tag');
        $tagged = $ref->withTag('new-tag');

        self::assertSame('new-tag', $tagged->tag);
        self::assertSame('old-tag', $ref->tag);
    }

    public function test_to_string(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $ref = new ImageReference('repo', 'v1', $digest);

        self::assertSame('repo:v1@' . $digest, $ref->toString());
    }

    public function test_to_string_without_tag(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $ref = new ImageReference('repo', null, $digest);

        self::assertSame('repo@' . $digest, $ref->toString());
    }

    public function test_to_array_from_array_round_trip(): void
    {
        $digest = 'sha256:' . str_repeat('c', 64);
        $ref = new ImageReference('myrepo', 'v2', $digest);

        $arr = $ref->toArray();
        $restored = ImageReference::fromArray($arr);

        self::assertSame($ref->repository, $restored->repository);
        self::assertSame($ref->tag, $restored->tag);
        self::assertSame($ref->digest, $restored->digest);
    }
}
