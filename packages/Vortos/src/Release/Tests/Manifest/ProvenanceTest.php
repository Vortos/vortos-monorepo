<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Manifest\Provenance;

final class ProvenanceTest extends TestCase
{
    public function test_minimal_construction(): void
    {
        $p = new Provenance('github-actions');
        $this->assertSame('github-actions', $p->builderId);
        $this->assertNull($p->baseImageDigest);
        $this->assertNull($p->signature);
        $this->assertNull($p->attestation);
    }

    public function test_full_construction(): void
    {
        $p = new Provenance('ci', 'sha256:abc', 'sig', 'att');
        $this->assertSame('ci', $p->builderId);
        $this->assertSame('sha256:abc', $p->baseImageDigest);
        $this->assertSame('sig', $p->signature);
        $this->assertSame('att', $p->attestation);
    }

    public function test_to_array(): void
    {
        $p = new Provenance('ci', 'sha256:abc');
        $arr = $p->toArray();

        $this->assertSame('ci', $arr['builder_id']);
        $this->assertSame('sha256:abc', $arr['base_image_digest']);
        $this->assertNull($arr['signature']);
        $this->assertNull($arr['attestation']);
    }

    public function test_round_trip(): void
    {
        $original = new Provenance('ci', 'sha256:abc', 'sig', 'att');
        $restored = Provenance::fromArray($original->toArray());

        $this->assertSame($original->builderId, $restored->builderId);
        $this->assertSame($original->baseImageDigest, $restored->baseImageDigest);
        $this->assertSame($original->signature, $restored->signature);
        $this->assertSame($original->attestation, $restored->attestation);
    }

    public function test_from_array_with_minimal_data(): void
    {
        $p = Provenance::fromArray(['builder_id' => 'x']);
        $this->assertSame('x', $p->builderId);
        $this->assertNull($p->baseImageDigest);
    }
}
