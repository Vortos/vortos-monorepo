<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\ReleaseKey;

use Vortos\Deploy\PullAgent\DesiredStateManifest;
use Vortos\Deploy\PullAgent\ManifestSignerInterface;
use Vortos\Deploy\PullAgent\SignedDesiredStateManifest;
use Vortos\Secrets\Value\SecretValue;

final class ReleaseKeyManifestSigner implements ManifestSignerInterface
{
    public function __construct(
        private readonly SecretValue $signingKey,
        private readonly string $keyId,
    ) {}

    public function sign(DesiredStateManifest $manifest): SignedDesiredStateManifest
    {
        $message = $manifest->toCanonicalBytes();
        $signature = sodium_crypto_sign_detached($message, $this->signingKey->reveal());

        return new SignedDesiredStateManifest(
            manifest: $manifest,
            signature: base64_encode($signature),
            signerKeyId: $this->keyId,
        );
    }
}
