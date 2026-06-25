<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;

final class ArtifactDigestTest extends TestCase
{
    private const VALID = 'sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    public function test_valid_digest(): void
    {
        $digest = new ArtifactDigest(self::VALID);
        self::assertSame(self::VALID, $digest->value);
        self::assertSame(self::VALID, $digest->toString());
        self::assertSame('sha256', $digest->algorithm());
        self::assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', $digest->hex());
    }

    public function test_equals(): void
    {
        $a = new ArtifactDigest(self::VALID);
        $b = new ArtifactDigest(self::VALID);
        $c = new ArtifactDigest('sha256:0000000000000000000000000000000000000000000000000000000000000000');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function test_rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArtifactDigest('');
    }

    public function test_rejects_short_hex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArtifactDigest('sha256:abc');
    }

    public function test_rejects_uppercase_hex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArtifactDigest('sha256:A1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4E5F6A1B2');
    }

    public function test_rejects_non_hex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArtifactDigest('sha256:zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz');
    }

    public function test_rejects_wrong_prefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArtifactDigest('md5:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2');
    }

    public function test_rejects_no_prefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArtifactDigest('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2');
    }
}
