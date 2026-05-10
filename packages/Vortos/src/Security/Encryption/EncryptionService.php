<?php

declare(strict_types=1);

namespace Vortos\Security\Encryption;

use Vortos\Security\Contract\EncryptionInterface;

/**
 * AES-256-GCM authenticated encryption service.
 *
 * ## Security properties
 *
 *  - AES-256-GCM provides Authenticated Encryption with Associated Data (AEAD).
 *    Both confidentiality (encryption) and integrity (authentication tag) are
 *    guaranteed in a single operation.
 *  - A fresh 12-byte random nonce (IV) is generated for every encrypt() call.
 *    Reusing the nonce with the same key is catastrophic — random nonces with a
 *    256-bit key have negligible collision probability across billions of records.
 *  - The context string is used as Additional Authenticated Data (AAD). This
 *    binds the ciphertext to its intended field, preventing cross-field ciphertext
 *    transplant attacks.
 *
 * ## Ciphertext format (base64 of binary)
 *
 *   [ 4 bytes version ] [ 12 bytes nonce ] [ 16 bytes auth tag ] [ N bytes ciphertext ]
 *
 * The version prefix enables key rotation: store the key version, detect old
 * ciphertexts, and re-encrypt lazily.
 *
 * ## Performance
 *
 * openssl_encrypt with AES-256-GCM is hardware-accelerated on x86 (AES-NI) and
 * ARM (ARMv8 Crypto Extensions). Overhead per operation is ~1 µs for small values.
 */
final class EncryptionService implements EncryptionInterface
{
    private const NONCE_LENGTH   = 12;
    private const TAG_LENGTH     = 16;
    private const VERSION_PREFIX = "\x01\x00\x00\x00"; // version 1

    public function __construct(
        private readonly KeyDerivationService $keyDerivation,
        private readonly string $algorithm = 'aes-256-gcm',
    ) {}

    public function encrypt(string $plaintext, string $context = ''): string
    {
        $key   = $this->keyDerivation->deriveKey($context);
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag   = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->algorithm,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $context,     // AAD
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('vortos-security: AES-256-GCM encryption failed.');
        }

        $binary = self::VERSION_PREFIX . $nonce . $tag . $ciphertext;
        return base64_encode($binary);
    }

    public function decrypt(string $ciphertext, string $context = ''): string
    {
        $binary = base64_decode($ciphertext, strict: true);
        if ($binary === false) {
            throw new \RuntimeException('vortos-security: ciphertext is not valid base64.');
        }

        $minLength = strlen(self::VERSION_PREFIX) + self::NONCE_LENGTH + self::TAG_LENGTH;
        if (strlen($binary) < $minLength) {
            throw new \RuntimeException('vortos-security: ciphertext is too short to be valid.');
        }

        $offset  = 0;
        $version = substr($binary, $offset, 4); $offset += 4;
        $nonce   = substr($binary, $offset, self::NONCE_LENGTH); $offset += self::NONCE_LENGTH;
        $tag     = substr($binary, $offset, self::TAG_LENGTH); $offset += self::TAG_LENGTH;
        $data    = substr($binary, $offset);

        if ($version !== self::VERSION_PREFIX) {
            throw new \RuntimeException(
                'vortos-security: unsupported ciphertext version. Key rotation may be required.'
            );
        }

        $key = $this->keyDerivation->deriveKey($context);

        $plaintext = openssl_decrypt(
            $data,
            $this->algorithm,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $context,  // AAD must match
        );

        if ($plaintext === false) {
            throw new \RuntimeException(
                'vortos-security: decryption failed — data may be tampered or context mismatch.'
            );
        }

        return $plaintext;
    }
}
