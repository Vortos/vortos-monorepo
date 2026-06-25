<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Model\Attestation;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Value\SecretValue;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Attestation\AttestationBundle;
use Vortos\Security\SupplyChain\Model\Provenance\ProvenanceBuilder;
use Vortos\Security\SupplyChain\Model\Provenance\ProvenanceSubject;
use Vortos\Security\SupplyChain\Model\Provenance\SlsaProvenance;
use Vortos\Security\SupplyChain\Model\Sbom\SbomComponent;
use Vortos\Security\SupplyChain\Model\Sbom\SbomDocument;
use Vortos\Security\SupplyChain\Model\Sbom\SbomFormat;
use Vortos\Security\SupplyChain\Model\Signature\Signature;
use Vortos\Security\SupplyChain\Model\Signature\SignatureScheme;

final class AttestationBundleTest extends TestCase
{
    private const DIGEST = 'sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    public function test_content_hash_is_deterministic(): void
    {
        $bundle = $this->fullBundle();
        self::assertSame($bundle->contentHash(), $bundle->contentHash());
    }

    public function test_content_hash_changes_on_different_digest(): void
    {
        $a = new AttestationBundle(new ArtifactDigest(self::DIGEST));
        $b = new AttestationBundle(new ArtifactDigest('sha256:0000000000000000000000000000000000000000000000000000000000000000'));
        self::assertNotSame($a->contentHash(), $b->contentHash());
    }

    public function test_has_methods(): void
    {
        $empty = new AttestationBundle(new ArtifactDigest(self::DIGEST));
        self::assertFalse($empty->hasSbom());
        self::assertFalse($empty->hasSignature());
        self::assertFalse($empty->hasProvenance());

        $full = $this->fullBundle();
        self::assertTrue($full->hasSbom());
        self::assertTrue($full->hasSignature());
        self::assertTrue($full->hasProvenance());
    }

    public function test_to_array(): void
    {
        $bundle = $this->fullBundle();
        $arr = $bundle->toArray();
        self::assertSame(self::DIGEST, $arr['artifact_digest']);
        self::assertTrue($arr['has_sbom']);
        self::assertTrue($arr['has_signature']);
        self::assertTrue($arr['has_provenance']);
        self::assertStringStartsWith('sha256:', $arr['content_hash']);
    }

    private function fullBundle(): AttestationBundle
    {
        return new AttestationBundle(
            artifactDigest: new ArtifactDigest(self::DIGEST),
            sbom: new SbomDocument(SbomFormat::CycloneDxJson, '1.5', [new SbomComponent('pkg', '1.0')]),
            signature: new Signature(SignatureScheme::KeylessFulcio, SecretValue::fromString('sig'), 42),
            provenance: new SlsaProvenance(
                'https://slsa.dev/provenance/v1',
                new ProvenanceBuilder('builder-id'),
                'type',
                [new ProvenanceSubject('image', new ArtifactDigest(self::DIGEST))],
            ),
        );
    }
}
