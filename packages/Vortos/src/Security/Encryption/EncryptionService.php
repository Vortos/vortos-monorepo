<?php

declare(strict_types=1);

namespace Vortos\Security\Encryption;

use SensitiveParameter;
use Vortos\Security\Contract\EncryptionInterface;

/**
 * AES-256-GCM authenticated encryption under a rotatable keyring.
 *
 * ## Security properties
 *
 *  - AES-256-GCM is authenticated encryption: a changed byte anywhere fails decryption rather than
 *    yielding a different plaintext.
 *  - A fresh 12-byte random nonce for every encrypt() call.
 *  - Each (key, context) pair has its own sub-key (EncryptionKeyring, HKDF-SHA256).
 *  - The header and the context are Additional Authenticated Data. A ciphertext moved to another
 *    field, or to another row when the context names the row, fails to decrypt; so does one whose
 *    key id was edited.
 *
 * ## Ciphertext format (base64 of binary)
 *
 *   v2 (written):  [ 0x02 ] [ 4 bytes key id ] [ 12 bytes nonce ] [ 16 bytes tag ] [ ciphertext ]
 *   v1 (read only): [ 01 00 00 00 ] [ 12 bytes nonce ] [ 16 bytes tag ] [ ciphertext ]
 *
 * v1 carried no key id; it is decrypted by trying each key the keyring holds, and always reports
 * needsReencryption(), so a sweep moves it to v2.
 */
final class EncryptionService implements EncryptionInterface
{
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH   = 16;
    private const ALGORITHM    = 'aes-256-gcm';
    private const V1_PREFIX    = "\x01\x00\x00\x00";
    private const V2           = "\x02";

    public function __construct(
        private readonly EncryptionKeyring $keyring,
        string $algorithm = self::ALGORITHM,
    ) {
        if ($algorithm !== self::ALGORITHM) {
            throw new \InvalidArgumentException(sprintf(
                'vortos-security: only %s is supported for field encryption; "%s" was configured.',
                self::ALGORITHM,
                $algorithm,
            ));
        }
    }

    public function encrypt(#[SensitiveParameter] string $plaintext, string $context = ''): string
    {
        $keyId  = $this->keyring->activeKeyId();
        $header = self::V2 . $keyId;
        $nonce  = random_bytes(self::NONCE_LENGTH);
        $tag    = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::ALGORITHM,
            $this->keyring->subKey($keyId, $context),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $header . $context,
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('vortos-security: AES-256-GCM encryption failed.');
        }

        return base64_encode($header . $nonce . $tag . $ciphertext);
    }

    public function decrypt(string $ciphertext, string $context = ''): string
    {
        $binary = $this->binary($ciphertext);

        if ($binary[0] === self::V2) {
            $header = substr($binary, 0, 1 + EncryptionKeyring::KEY_ID_BYTES);
            $keyId  = substr($header, 1);
            $plain  = $this->open(substr($binary, strlen($header)), $this->keyring->subKey($keyId, $context), $header . $context);

            if ($plain === null) {
                throw new \RuntimeException('vortos-security: decryption failed — data may be tampered or context mismatch.');
            }

            return $plain;
        }

        // v1: no key id, so every key the keyring holds is tried; the tag tells the right one.
        foreach ($this->keyring->keyIds() as $keyId) {
            $plain = $this->open(substr($binary, strlen(self::V1_PREFIX)), $this->keyring->subKey($keyId, $context), $context);
            if ($plain !== null) {
                return $plain;
            }
        }

        throw new \RuntimeException('vortos-security: decryption failed — data may be tampered, context mismatch, or the key is no longer held.');
    }

    public function needsReencryption(string $ciphertext): bool
    {
        $binary = $this->binary($ciphertext);

        return $binary[0] !== self::V2
            || substr($binary, 1, EncryptionKeyring::KEY_ID_BYTES) !== $this->keyring->activeKeyId();
    }

    /** The decoded binary, refused unless it is a whole v1 or v2 ciphertext. */
    private function binary(string $ciphertext): string
    {
        $binary = base64_decode($ciphertext, strict: true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('vortos-security: ciphertext is not valid base64.');
        }

        $header = match (true) {
            $binary[0] === self::V2                                  => 1 + EncryptionKeyring::KEY_ID_BYTES,
            str_starts_with($binary, self::V1_PREFIX)                => strlen(self::V1_PREFIX),
            default => throw new \RuntimeException('vortos-security: unsupported ciphertext version.'),
        };

        if (strlen($binary) < $header + self::NONCE_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('vortos-security: ciphertext is too short to be valid.');
        }

        return $binary;
    }

    /** @return string|null the plaintext, or null when the tag does not verify */
    private function open(string $body, string $key, string $aad): ?string
    {
        $nonce = substr($body, 0, self::NONCE_LENGTH);
        $tag   = substr($body, self::NONCE_LENGTH, self::TAG_LENGTH);
        $data  = substr($body, self::NONCE_LENGTH + self::TAG_LENGTH);

        $plain = openssl_decrypt($data, self::ALGORITHM, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

        return $plain === false ? null : $plain;
    }
}
