<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Secrets\Value\SecretValue;

final readonly class EphemeralKeyPair
{
    public function __construct(
        public SecretValue $privateKey,
        public string $publicKey,
    ) {
        if ($publicKey === '') {
            throw new \InvalidArgumentException('Public key must not be empty.');
        }
    }
}
