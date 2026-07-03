<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Manifest\Provenance;
use Vortos\Release\Schema\SchemaFingerprint;
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
use Vortos\Security\SupplyChain\Service\SupplyChainManifestDecorator;

final class ManifestDecoratorContractTest extends TestCase
{
    private const DIGEST = 'sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    public function test_decorator_populates_provenance_fields(): void
    {
        $manifest = $this->baseManifest();
        $bundle = $this->fullBundle();

        $decorator = new SupplyChainManifestDecorator();
        $decorated = $decorator->decorate($manifest, $bundle);

        self::assertNotNull($decorated->provenance);
        self::assertNotNull($decorated->provenance->signature);
        self::assertNotNull($decorated->provenance->attestation);
        self::assertStringStartsWith('sha256:', $decorated->provenance->attestation);
    }

    public function test_decorator_preserves_existing_fields(): void
    {
        $manifest = $this->manifestWithProvenance();
        $bundle = $this->fullBundle();

        $decorator = new SupplyChainManifestDecorator();
        $decorated = $decorator->decorate($manifest, $bundle);

        self::assertSame('https://github.com/actions/runner', $decorated->provenance->builderId);
        self::assertSame($manifest->buildId, $decorated->buildId);
        self::assertSame($manifest->imageDigest, $decorated->imageDigest);
    }

    public function test_decorated_manifest_round_trips(): void
    {
        $manifest = $this->baseManifest();
        $bundle = $this->fullBundle();

        $decorator = new SupplyChainManifestDecorator();
        $decorated = $decorator->decorate($manifest, $bundle);

        $serialized = $decorated->toArray();
        $restored = BuildManifest::fromArray($serialized);

        self::assertSame($decorated->buildId, $restored->buildId);
        self::assertNotNull($restored->provenance);
        self::assertSame($decorated->provenance->attestation, $restored->provenance->attestation);
    }

    private function baseManifest(): BuildManifest
    {
        return new BuildManifest(
            buildId: 'build-1',
            gitSha: 'abcdef1',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: self::DIGEST,
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable('2024-06-01T00:00:00Z'),
        );
    }

    private function manifestWithProvenance(): BuildManifest
    {
        return new BuildManifest(
            buildId: 'build-1',
            gitSha: 'abcdef1',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: self::DIGEST,
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable('2024-06-01T00:00:00Z'),
            provenance: new Provenance('https://github.com/actions/runner', self::DIGEST),
        );
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
