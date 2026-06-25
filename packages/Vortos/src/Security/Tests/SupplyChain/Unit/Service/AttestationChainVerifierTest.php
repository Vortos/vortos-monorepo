<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Driver\InMemory\InMemoryArtifactSigner;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Attestation\AttestationBundle;
use Vortos\Security\SupplyChain\Model\Provenance\ProvenanceBuilder;
use Vortos\Security\SupplyChain\Model\Provenance\ProvenanceMaterial;
use Vortos\Security\SupplyChain\Model\Provenance\ProvenanceSubject;
use Vortos\Security\SupplyChain\Model\Provenance\SlsaProvenance;
use Vortos\Security\SupplyChain\Model\ProvenanceChainException;
use Vortos\Security\SupplyChain\Model\Sbom\SbomComponent;
use Vortos\Security\SupplyChain\Model\Sbom\SbomDocument;
use Vortos\Security\SupplyChain\Model\Sbom\SbomFormat;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Service\AttestationChainVerifier;

final class AttestationChainVerifierTest extends TestCase
{
    private const DIGEST = 'sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const BASE_DIGEST = 'sha256:0000000000000000000000000000000000000000000000000000000000000001';
    private const WRONG_DIGEST = 'sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';
    private const BUILDER_ID = 'https://github.com/actions/runner';

    private AttestationChainVerifier $verifier;
    private InMemoryArtifactSigner $signer;
    private VerificationPolicy $policy;

    protected function setUp(): void
    {
        $this->verifier = new AttestationChainVerifier();
        $this->signer = new InMemoryArtifactSigner();
        $this->policy = VerificationPolicy::publicKey($this->signer->publicKeyFingerprint());
    }

    public function test_valid_chain_passes(): void
    {
        $digest = new ArtifactDigest(self::DIGEST);
        $this->signer->sign($digest);

        $bundle = $this->fullBundle($digest);
        $this->verifier->verify($bundle, $digest, self::BUILDER_ID, self::BASE_DIGEST, $this->policy, $this->signer);
        $this->addToAssertionCount(1);
    }

    public function test_subject_digest_mismatch_fails(): void
    {
        $digest = new ArtifactDigest(self::DIGEST);
        $wrongDigest = new ArtifactDigest(self::WRONG_DIGEST);
        $bundle = new AttestationBundle($wrongDigest);

        $this->expectException(ProvenanceChainException::class);
        $this->expectExceptionMessage('mismatch');
        $this->verifier->verify($bundle, $digest, self::BUILDER_ID, null, $this->policy, $this->signer);
    }

    public function test_wrong_builder_id_fails(): void
    {
        $digest = new ArtifactDigest(self::DIGEST);
        $this->signer->sign($digest);
        $bundle = $this->fullBundle($digest);

        $this->expectException(ProvenanceChainException::class);
        $this->expectExceptionMessage('builder');
        $this->verifier->verify($bundle, $digest, 'wrong-builder', self::BASE_DIGEST, $this->policy, $this->signer);
    }

    public function test_provenance_subject_not_matching_image_fails(): void
    {
        $digest = new ArtifactDigest(self::DIGEST);
        $otherDigest = new ArtifactDigest(self::WRONG_DIGEST);
        $this->signer->sign($digest);

        $prov = new SlsaProvenance(
            'https://slsa.dev/provenance/v1',
            new ProvenanceBuilder(self::BUILDER_ID),
            'type',
            [new ProvenanceSubject('other-image', $otherDigest)],
        );

        $bundle = new AttestationBundle($digest, provenance: $prov, signature: $this->signer->sign($digest));

        $this->expectException(ProvenanceChainException::class);
        $this->expectExceptionMessage('not found');
        $this->verifier->verify($bundle, $digest, self::BUILDER_ID, null, $this->policy, $this->signer);
    }

    public function test_base_image_mismatch_fails(): void
    {
        $digest = new ArtifactDigest(self::DIGEST);
        $this->signer->sign($digest);
        $bundle = $this->fullBundle($digest);

        $this->expectException(ProvenanceChainException::class);
        $this->expectExceptionMessage('Base image');
        $this->verifier->verify($bundle, $digest, self::BUILDER_ID, self::WRONG_DIGEST, $this->policy, $this->signer);
    }

    public function test_bundle_without_provenance_passes(): void
    {
        $digest = new ArtifactDigest(self::DIGEST);
        $this->signer->sign($digest);
        $bundle = new AttestationBundle($digest, signature: $this->signer->sign($digest));

        $this->verifier->verify($bundle, $digest, self::BUILDER_ID, null, $this->policy, $this->signer);
        $this->addToAssertionCount(1);
    }

    public function test_bundle_without_signature_passes(): void
    {
        $digest = new ArtifactDigest(self::DIGEST);
        $bundle = new AttestationBundle($digest);

        $this->verifier->verify($bundle, $digest, self::BUILDER_ID, null, $this->policy, $this->signer);
        $this->addToAssertionCount(1);
    }

    private function fullBundle(ArtifactDigest $digest): AttestationBundle
    {
        return new AttestationBundle(
            artifactDigest: $digest,
            sbom: new SbomDocument(SbomFormat::CycloneDxJson, '1.5', [new SbomComponent('pkg', '1.0')]),
            signature: $this->signer->sign($digest),
            provenance: new SlsaProvenance(
                'https://slsa.dev/provenance/v1',
                new ProvenanceBuilder(self::BUILDER_ID),
                'type',
                [new ProvenanceSubject('image', $digest)],
                [new ProvenanceMaterial('base-image', new ArtifactDigest(self::BASE_DIGEST))],
            ),
        );
    }
}
