<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Secrets\Value\SecretValue;

final class EphemeralKeyPairFactory
{
    public function generate(): EphemeralKeyPair
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        sodium_memzero($keypair);

        $publicKeyEncoded = 'ssh-ed25519 ' . base64_encode(
            pack('Na*Na*', 11, 'ssh-ed25519', 32, $publicKey),
        );

        return new EphemeralKeyPair(
            privateKey: SecretValue::fromString($secretKey),
            publicKey: $publicKeyEncoded,
        );
    }
}
