<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Driver\Null\NullArtifactSigner;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;

final class ProdSafetyTest extends TestCase
{
    public function test_null_signer_without_dev_flag_rejects_verify(): void
    {
        $signer = new NullArtifactSigner(devUnsafeSkipVerification: false);
        $digest = new ArtifactDigest('sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2');
        $policy = VerificationPolicy::publicKey('test');

        $this->expectException(\Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException::class);
        $signer->verify($digest, $policy);
    }

    public function test_null_signer_with_dev_flag_returns_success(): void
    {
        $signer = new NullArtifactSigner(devUnsafeSkipVerification: true);
        $digest = new ArtifactDigest('sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2');
        $policy = VerificationPolicy::publicKey('test');

        $result = $signer->verify($digest, $policy);
        self::assertTrue($result->ok);
    }

    public function test_null_signer_sign_always_rejects(): void
    {
        $signer = new NullArtifactSigner(devUnsafeSkipVerification: true);
        $digest = new ArtifactDigest('sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2');

        $this->expectException(\Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException::class);
        $signer->sign($digest);
    }
}
