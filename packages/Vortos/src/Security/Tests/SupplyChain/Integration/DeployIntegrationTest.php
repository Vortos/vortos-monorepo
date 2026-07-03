<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;
use Vortos\Security\SupplyChain\Driver\InMemory\InMemoryArtifactSigner;
use Vortos\Security\SupplyChain\Integration\Deploy\AttestationImageSigner;
use Vortos\Security\SupplyChain\Integration\Deploy\SignatureVerificationCheck;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Port\ArtifactSignerRegistry;

final class DeployIntegrationTest extends TestCase
{
    private const DIGEST = 'sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    public function test_attestation_image_signer_refuses_unsigned(): void
    {
        $signer = new InMemoryArtifactSigner();
        $registry = $this->registryWith($signer);
        $policy = VerificationPolicy::publicKey($signer->publicKeyFingerprint());

        $bridge = new AttestationImageSigner($registry, 'in-memory', $policy);
        $image = new ImageReference('ghcr.io/org/app', 'v1', self::DIGEST);

        self::assertFalse($bridge->verify($image));
    }

    public function test_attestation_image_signer_accepts_signed(): void
    {
        $signer = new InMemoryArtifactSigner();
        $signer->sign(new ArtifactDigest(self::DIGEST));
        $registry = $this->registryWith($signer);
        $policy = VerificationPolicy::publicKey($signer->publicKeyFingerprint());

        $bridge = new AttestationImageSigner($registry, 'in-memory', $policy);
        $image = new ImageReference('ghcr.io/org/app', 'v1', self::DIGEST);

        self::assertTrue($bridge->verify($image));
    }

    public function test_attestation_image_signer_refuses_no_digest(): void
    {
        $signer = new InMemoryArtifactSigner();
        $registry = $this->registryWith($signer);
        $policy = VerificationPolicy::publicKey($signer->publicKeyFingerprint());

        $bridge = new AttestationImageSigner($registry, 'in-memory', $policy);
        $image = new ImageReference('ghcr.io/org/app', 'v1');

        self::assertFalse($bridge->verify($image));
    }

    public function test_signature_verification_check_fails_unsigned(): void
    {
        if (!class_exists(PreflightContext::class)) {
            self::markTestSkipped('Deploy package not available.');
        }

        $signer = new InMemoryArtifactSigner();
        $registry = $this->registryWith($signer);
        $policy = VerificationPolicy::publicKey($signer->publicKeyFingerprint());

        $check = new SignatureVerificationCheck($registry, 'in-memory', $policy);

        self::assertSame('security.signature', $check->id());
        self::assertSame(PreflightCategory::Security, $check->category());

        $context = $this->preflightContext();
        $finding = $check->check($context);

        self::assertSame(PreflightStatus::Fail, $finding->status);
    }

    public function test_signature_verification_check_passes_signed(): void
    {
        if (!class_exists(PreflightContext::class)) {
            self::markTestSkipped('Deploy package not available.');
        }

        $signer = new InMemoryArtifactSigner();
        $signer->sign(new ArtifactDigest(self::DIGEST));
        $registry = $this->registryWith($signer);
        $policy = VerificationPolicy::publicKey($signer->publicKeyFingerprint());

        $check = new SignatureVerificationCheck($registry, 'in-memory', $policy);
        $context = $this->preflightContext();
        $finding = $check->check($context);

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_signature_verification_check_skips_without_policy(): void
    {
        if (!class_exists(PreflightContext::class)) {
            self::markTestSkipped('Deploy package not available.');
        }

        $signer = new InMemoryArtifactSigner();
        $registry = $this->registryWith($signer);

        $check = new SignatureVerificationCheck($registry, 'in-memory');
        $context = $this->preflightContext();
        $finding = $check->check($context);

        self::assertSame(PreflightStatus::Skip, $finding->status);
    }

    private function registryWith(InMemoryArtifactSigner $signer): ArtifactSignerRegistry
    {
        $locator = new \Symfony\Component\DependencyInjection\ServiceLocator([
            'in-memory' => fn () => $signer,
        ]);

        return new ArtifactSignerRegistry($locator);
    }

    private function preflightContext(): PreflightContext
    {
        $manifest = new BuildManifest(
            buildId: 'test-build',
            gitSha: 'abcdef1',
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: self::DIGEST,
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );

        return new PreflightContext(
            definition: DeploymentDefinition::build(),
            desiredManifest: $manifest,
            currentState: CurrentDeployState::firstDeploy(),
            environment: new EnvironmentName('production'),
        );
    }
}
