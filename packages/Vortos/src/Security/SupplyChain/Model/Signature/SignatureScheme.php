<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Signature;

enum SignatureScheme: string
{
    case KeylessFulcio = 'keyless-fulcio';
    case KeyEd25519 = 'key-ed25519';
    case KeyEcdsaP256 = 'key-ecdsa-p256';

    public function isKeyless(): bool
    {
        return $this === self::KeylessFulcio;
    }
}
