<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Model\Provenance;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Provenance\ProvenanceBuilder;
use Vortos\Security\SupplyChain\Model\Provenance\ProvenanceMaterial;
use Vortos\Security\SupplyChain\Model\Provenance\ProvenanceSubject;
use Vortos\Security\SupplyChain\Model\Provenance\SlsaProvenance;

final class SlsaProvenanceTest extends TestCase
{
    private const DIGEST = 'sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    public function test_construct_and_round_trip(): void
    {
        $prov = $this->sampleProvenance();
        $restored = SlsaProvenance::fromArray($prov->toArray());

        self::assertSame($prov->predicateType, $restored->predicateType);
        self::assertSame($prov->builder->builderId, $restored->builder->builderId);
        self::assertCount(1, $restored->subjects);
        self::assertCount(1, $restored->materials);
    }

    public function test_rejects_empty_predicate_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SlsaProvenance('', new ProvenanceBuilder('builder'), 'type', [new ProvenanceSubject('s', new ArtifactDigest(self::DIGEST))]);
    }

    public function test_rejects_empty_build_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SlsaProvenance('predicate', new ProvenanceBuilder('builder'), '', [new ProvenanceSubject('s', new ArtifactDigest(self::DIGEST))]);
    }

    public function test_rejects_empty_subjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SlsaProvenance('predicate', new ProvenanceBuilder('builder'), 'type', []);
    }

    public function test_builder_rejects_empty_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProvenanceBuilder('');
    }

    public function test_subject_rejects_empty_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProvenanceSubject('', new ArtifactDigest(self::DIGEST));
    }

    public function test_material_rejects_empty_uri(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProvenanceMaterial('', new ArtifactDigest(self::DIGEST));
    }

    private function sampleProvenance(): SlsaProvenance
    {
        return new SlsaProvenance(
            predicateType: 'https://slsa.dev/provenance/v1',
            builder: new ProvenanceBuilder('https://github.com/actions/runner', 'v2'),
            buildType: 'https://github.com/slsa-framework/slsa-github-generator/generic@v1',
            subjects: [new ProvenanceSubject('image', new ArtifactDigest(self::DIGEST))],
            materials: [new ProvenanceMaterial('git+https://github.com/org/repo', new ArtifactDigest(self::DIGEST))],
        );
    }
}
