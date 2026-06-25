<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Crypto\EnvelopeCipher;
use Vortos\Secrets\Exception\DecryptionFailedException;

final class EnvelopeCipherTest extends TestCase
{
    public function test_generate_data_key_produces_32_bytes(): void
    {
        $cipher = new EnvelopeCipher();

        self::assertSame(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, strlen($cipher->generateDataKey()));
    }

    public function test_two_generated_data_keys_differ(): void
    {
        $cipher = new EnvelopeCipher();

        self::assertNotSame($cipher->generateDataKey(), $cipher->generateDataKey());
    }

    public function test_encrypt_and_decrypt_payload_round_trips(): void
    {
        $cipher = new EnvelopeCipher();
        $dek = $cipher->generateDataKey();

        $payload = $cipher->encryptPayload('top secret plaintext', $dek);
        $plaintext = $cipher->decryptPayload($payload['ciphertext'], $payload['nonce'], $dek);

        self::assertSame('top secret plaintext', $plaintext);
    }

    public function test_round_trips_empty_string(): void
    {
        $cipher = new EnvelopeCipher();
        $dek = $cipher->generateDataKey();

        $payload = $cipher->encryptPayload('', $dek);

        self::assertSame('', $cipher->decryptPayload($payload['ciphertext'], $payload['nonce'], $dek));
    }

    public function test_round_trips_binary_payload(): void
    {
        $cipher = new EnvelopeCipher();
        $dek = $cipher->generateDataKey();
        $binary = random_bytes(256);

        $payload = $cipher->encryptPayload($binary, $dek);

        self::assertSame($binary, $cipher->decryptPayload($payload['ciphertext'], $payload['nonce'], $dek));
    }

    public function test_nonce_is_24_bytes(): void
    {
        $cipher = new EnvelopeCipher();
        $dek = $cipher->generateDataKey();

        $payload = $cipher->encryptPayload('plaintext', $dek);

        self::assertSame(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES, strlen($payload['nonce']));
    }

    public function test_each_encryption_produces_a_different_nonce_and_ciphertext(): void
    {
        $cipher = new EnvelopeCipher();
        $dek = $cipher->generateDataKey();

        $first = $cipher->encryptPayload('same plaintext', $dek);
        $second = $cipher->encryptPayload('same plaintext', $dek);

        self::assertNotSame($first['nonce'], $second['nonce']);
        self::assertNotSame($first['ciphertext'], $second['ciphertext']);
    }

    public function test_tampered_ciphertext_fails_closed(): void
    {
        $cipher = new EnvelopeCipher();
        $dek = $cipher->generateDataKey();
        $payload = $cipher->encryptPayload('plaintext', $dek);

        $tampered = $payload['ciphertext'];
        $tampered[0] = $tampered[0] === "\x00" ? "\x01" : "\x00";

        $this->expectException(DecryptionFailedException::class);
        $cipher->decryptPayload($tampered, $payload['nonce'], $dek);
    }

    public function test_tampered_nonce_fails_closed(): void
    {
        $cipher = new EnvelopeCipher();
        $dek = $cipher->generateDataKey();
        $payload = $cipher->encryptPayload('plaintext', $dek);

        $tamperedNonce = $payload['nonce'];
        $tamperedNonce[0] = $tamperedNonce[0] === "\x00" ? "\x01" : "\x00";

        $this->expectException(DecryptionFailedException::class);
        $cipher->decryptPayload($payload['ciphertext'], $tamperedNonce, $dek);
    }

    public function test_tampered_aad_fails_closed(): void
    {
        $cipher = new EnvelopeCipher();
        $dek = $cipher->generateDataKey();
        $payload = $cipher->encryptPayload('plaintext', $dek);

        $this->expectException(DecryptionFailedException::class);
        $cipher->decryptPayload($payload['ciphertext'], $payload['nonce'], $dek, 'tampered-aad');
    }

    public function test_wrong_data_key_fails_closed(): void
    {
        $cipher = new EnvelopeCipher();
        $dek = $cipher->generateDataKey();
        $wrongDek = $cipher->generateDataKey();
        $payload = $cipher->encryptPayload('plaintext', $dek);

        $this->expectException(DecryptionFailedException::class);
        $cipher->decryptPayload($payload['ciphertext'], $payload['nonce'], $wrongDek);
    }
}
