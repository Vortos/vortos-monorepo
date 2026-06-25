<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\PullAgent\DesiredStateManifest;
use Vortos\Deploy\PullAgent\ManifestSignerInterface;
use Vortos\Deploy\PullAgent\SignedDesiredStateManifest;

final class FakeManifestSigner implements ManifestSignerInterface
{
    private readonly string $secretKey;
    private readonly string $publicKey;
    private readonly string $keyId;

    public function __construct()
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        $this->keyId = 'fake-key-' . bin2hex(random_bytes(4));
    }

    public function sign(DesiredStateManifest $manifest): SignedDesiredStateManifest
    {
        $message = $manifest->toCanonicalBytes();
        $signature = sodium_crypto_sign_detached($message, $this->secretKey);

        return new SignedDesiredStateManifest(
            manifest: $manifest,
            signature: base64_encode($signature),
            signerKeyId: $this->keyId,
        );
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }
}
