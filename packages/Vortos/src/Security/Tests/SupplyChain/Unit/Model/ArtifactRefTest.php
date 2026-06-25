<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\ArtifactRef;

final class ArtifactRefTest extends TestCase
{
    private const DIGEST = 'sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    public function test_construct_and_to_string(): void
    {
        $ref = new ArtifactRef('ghcr.io/org/app', new ArtifactDigest(self::DIGEST), 'v1.0');
        self::assertSame('ghcr.io/org/app:v1.0@' . self::DIGEST, $ref->toString());
    }

    public function test_without_tag(): void
    {
        $ref = new ArtifactRef('ghcr.io/org/app', new ArtifactDigest(self::DIGEST));
        self::assertSame('ghcr.io/org/app@' . self::DIGEST, $ref->toString());
    }

    public function test_rejects_empty_repository(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArtifactRef('', new ArtifactDigest(self::DIGEST));
    }

    public function test_round_trips_via_array(): void
    {
        $ref = new ArtifactRef('ghcr.io/org/app', new ArtifactDigest(self::DIGEST), 'v1.0');
        $restored = ArtifactRef::fromArray($ref->toArray());

        self::assertSame($ref->repository, $restored->repository);
        self::assertTrue($ref->digest->equals($restored->digest));
        self::assertSame($ref->tag, $restored->tag);
    }
}
