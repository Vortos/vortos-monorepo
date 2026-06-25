<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\InMemory;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Value\SecretValue;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Signature\Signature;
use Vortos\Security\SupplyChain\Model\Signature\SignatureScheme;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Model\Signature\VerificationResult;
use Vortos\Security\SupplyChain\Port\ArtifactSignerInterface;

#[AsDriver('in-memory')]
final class InMemoryArtifactSigner implements ArtifactSignerInterface
{
    /** @var array<string, string> digest -> base64 signature */
    private array $signatures = [];

    private string $publicKeyFingerprint;
    private string $privateKey;
    private string $publicKey;

    public function __construct()
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->privateKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        $this->publicKeyFingerprint = hash('sha256', $this->publicKey);
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SupplyChainCapabilityKey::Signing->value => true,
            SupplyChainCapabilityKey::KeylessSigning->value => false,
            SupplyChainCapabilityKey::RekorTransparency->value => false,
        ]);
    }

    public function sign(ArtifactDigest $digest): Signature
    {
        $sig = sodium_crypto_sign_detached($digest->toString(), $this->privateKey);
        $encoded = base64_encode($sig);
        $this->signatures[$digest->toString()] = $encoded;

        return new Signature(
            scheme: SignatureScheme::KeyEd25519,
            payload: SecretValue::fromString($encoded),
        );
    }

    public function verify(ArtifactDigest $digest, VerificationPolicy $policy): VerificationResult
    {
        if ($policy->isKeyless()) {
            return VerificationResult::failure(['InMemory driver only supports key-based verification.']);
        }

        if (!$policy->matchesFingerprint($this->publicKeyFingerprint)) {
            return VerificationResult::failure(['Public key fingerprint mismatch.']);
        }

        $encoded = $this->signatures[$digest->toString()] ?? null;
        if ($encoded === null) {
            return VerificationResult::failure(['No signature found for digest.']);
        }

        $sigBytes = base64_decode($encoded, true);
        if ($sigBytes === false) {
            return VerificationResult::failure(['Corrupt signature payload.']);
        }

        $valid = sodium_crypto_sign_verify_detached($sigBytes, $digest->toString(), $this->publicKey);

        if (!$valid) {
            return VerificationResult::failure(['Ed25519 signature verification failed.']);
        }

        return VerificationResult::success();
    }

    public function publicKeyFingerprint(): string
    {
        return $this->publicKeyFingerprint;
    }
}
