<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\PullAgent;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Driver\ReleaseKey\ReleaseKeyManifestSigner;
use Vortos\Deploy\Driver\ReleaseKey\ReleaseKeyManifestVerifier;
use Vortos\Deploy\Exception\ManifestSignatureInvalidException;
use Vortos\Deploy\Exception\UnsignedManifestException;
use Vortos\Deploy\PullAgent\DesiredStateManifest;
use Vortos\Deploy\PullAgent\SignedDesiredStateManifest;
use Vortos\Secrets\Value\SecretValue;

final class ManifestSignVerifyTest extends TestCase
{
    private string $secretKey;
    private string $publicKey;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
    }

    public function test_sign_and_verify_round_trip(): void
    {
        $signer = new ReleaseKeyManifestSigner(SecretValue::fromString($this->secretKey), 'key-1');
        $verifier = new ReleaseKeyManifestVerifier($this->publicKey);

        $manifest = $this->makeManifest();
        $signed = $signer->sign($manifest);

        $verifier->verify($signed);

        $this->addToAssertionCount(1);
    }

    public function test_unsigned_manifest_rejected(): void
    {
        $verifier = new ReleaseKeyManifestVerifier($this->publicKey);

        $signed = new SignedDesiredStateManifest(
            manifest: $this->makeManifest(),
            signature: '',
            signerKeyId: 'key-1',
        );

        $this->expectException(UnsignedManifestException::class);

        $verifier->verify($signed);
    }

    public function test_tampered_manifest_rejected(): void
    {
        $signer = new ReleaseKeyManifestSigner(SecretValue::fromString($this->secretKey), 'key-1');
        $verifier = new ReleaseKeyManifestVerifier($this->publicKey);

        $manifest = $this->makeManifest();
        $signed = $signer->sign($manifest);

        $tampered = new SignedDesiredStateManifest(
            manifest: new DesiredStateManifest(
                env: 'HACKED',
                releaseVersion: $manifest->releaseVersion,
                imageDigest: $manifest->imageDigest,
                activeColor: $manifest->activeColor,
                composeProjection: $manifest->composeProjection,
                schemaFingerprint: $manifest->schemaFingerprint,
                issuedAt: $manifest->issuedAt,
                version: $manifest->version,
                nonce: $manifest->nonce,
            ),
            signature: $signed->signature,
            signerKeyId: $signed->signerKeyId,
        );

        $this->expectException(ManifestSignatureInvalidException::class);

        $verifier->verify($tampered);
    }

    public function test_wrong_key_rejected(): void
    {
        $signer = new ReleaseKeyManifestSigner(SecretValue::fromString($this->secretKey), 'key-1');

        $otherKeypair = sodium_crypto_sign_keypair();
        $otherPublic = sodium_crypto_sign_publickey($otherKeypair);
        $verifier = new ReleaseKeyManifestVerifier($otherPublic);

        $signed = $signer->sign($this->makeManifest());

        $this->expectException(ManifestSignatureInvalidException::class);

        $verifier->verify($signed);
    }

    public function test_invalid_base64_signature_rejected(): void
    {
        $verifier = new ReleaseKeyManifestVerifier($this->publicKey);

        $signed = new SignedDesiredStateManifest(
            manifest: $this->makeManifest(),
            signature: '!!!not-base64!!!',
            signerKeyId: 'key-1',
        );

        $this->expectException(ManifestSignatureInvalidException::class);
        $this->expectExceptionMessage('not valid base64');

        $verifier->verify($signed);
    }

    public function test_signer_key_id_is_preserved(): void
    {
        $signer = new ReleaseKeyManifestSigner(SecretValue::fromString($this->secretKey), 'release-key-v2');

        $signed = $signer->sign($this->makeManifest());

        $this->assertSame('release-key-v2', $signed->signerKeyId);
    }

    public function test_signed_manifest_round_trips_via_array(): void
    {
        $signer = new ReleaseKeyManifestSigner(SecretValue::fromString($this->secretKey), 'key-1');
        $verifier = new ReleaseKeyManifestVerifier($this->publicKey);

        $signed = $signer->sign($this->makeManifest());
        $restored = SignedDesiredStateManifest::fromArray($signed->toArray());

        $verifier->verify($restored);

        $this->addToAssertionCount(1);
    }

    private function makeManifest(): DesiredStateManifest
    {
        return new DesiredStateManifest(
            env: 'prod',
            releaseVersion: '1.0.0',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            activeColor: 'blue',
            composeProjection: '{"services":{}}',
            schemaFingerprint: 'fp-abc123',
            issuedAt: new \DateTimeImmutable('2026-06-23T12:00:00+00:00'),
            version: 1,
            nonce: 'test-nonce',
        );
    }
}
