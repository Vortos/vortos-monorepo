<?php

declare(strict_types=1);

namespace Vortos\Secrets\Driver\Age;

use InvalidArgumentException;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Crypto\Identity;
use Vortos\Secrets\Exception\DecryptionFailedException;
use Vortos\Secrets\Exception\KeyUnavailableException;
use Vortos\Secrets\Key\DataKey;
use Vortos\Secrets\Key\KeyProviderCapability;
use Vortos\Secrets\Key\KeyProviderInterface;
use Vortos\Secrets\Key\WrappedKey;

/**
 * `age`-style X25519 sealed-box key custody (§12.6/§12.7): the off-host half of
 * envelope encryption. `wrap()` only ever needs the (non-secret) public key,
 * supplied at construction; `unwrap()` sources the private identity off-host, at
 * use-time, from an environment variable — **never** from a tracked file. A
 * missing or malformed identity fails closed via {@see KeyUnavailableException};
 * a tampered/foreign-keyed wrapped key fails closed via
 * {@see DecryptionFailedException}.
 */
#[AsDriver('age')]
final class AgeKeyProvider implements KeyProviderInterface
{
    private const RECIPIENT_ID = 'default';

    private readonly string $publicKey;

    public function __construct(
        string $publicKeyBase64,
        private readonly string $identitySeedEnvVar = 'VORTOS_SECRETS_AGE_IDENTITY',
    ) {
        $decoded = base64_decode($publicKeyBase64, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('AgeKeyProvider public key must be a valid base64-encoded X25519 public key.');
        }

        $this->publicKey = $decoded;
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            KeyProviderCapability::OffHostKey->value => true,
            KeyProviderCapability::Wrap->value => true,
            KeyProviderCapability::Unwrap->value => true,
            KeyProviderCapability::EnvelopeEncryption->value => true,
        ]);
    }

    public function wrap(DataKey $dataKey): WrappedKey
    {
        $sealed = sodium_crypto_box_seal($dataKey->revealForEncryption(), $this->publicKey);

        return new WrappedKey($sealed, self::RECIPIENT_ID);
    }

    public function unwrap(WrappedKey $wrappedKey): DataKey
    {
        $identity = $this->loadIdentity();

        $opened = @sodium_crypto_box_seal_open($wrappedKey->ciphertext, $identity->revealKeyPairForUnsealing());
        if ($opened === false) {
            throw DecryptionFailedException::keyUnwrapFailed();
        }

        return DataKey::fromRaw($opened);
    }

    private function loadIdentity(): Identity
    {
        $seed = getenv($this->identitySeedEnvVar);
        if ($seed === false || $seed === '') {
            throw KeyUnavailableException::identityNotConfigured($this->identitySeedEnvVar);
        }

        try {
            return Identity::fromBase64Seed($seed);
        } catch (InvalidArgumentException $e) {
            throw KeyUnavailableException::identityMalformed($e->getMessage());
        }
    }
}
