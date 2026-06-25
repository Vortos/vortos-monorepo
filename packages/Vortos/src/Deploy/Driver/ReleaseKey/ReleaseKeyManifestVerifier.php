<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\ReleaseKey;

use Vortos\Deploy\Exception\ManifestSignatureInvalidException;
use Vortos\Deploy\Exception\UnsignedManifestException;
use Vortos\Deploy\PullAgent\ManifestVerifierInterface;
use Vortos\Deploy\PullAgent\SignedDesiredStateManifest;

final class ReleaseKeyManifestVerifier implements ManifestVerifierInterface
{
    public function __construct(
        private readonly string $publicKey,
    ) {}

    public function verify(SignedDesiredStateManifest $signed): void
    {
        if ($signed->signature === '') {
            throw UnsignedManifestException::create();
        }

        $signatureBytes = base64_decode($signed->signature, true);
        if ($signatureBytes === false) {
            throw ManifestSignatureInvalidException::create('Signature is not valid base64.');
        }

        $message = $signed->manifest->toCanonicalBytes();

        $valid = sodium_crypto_sign_verify_detached($signatureBytes, $message, $this->publicKey);

        if (!$valid) {
            throw ManifestSignatureInvalidException::create();
        }
    }
}
