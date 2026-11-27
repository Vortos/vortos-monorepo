<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\Encryption;

use PHPUnit\Framework\TestCase;
use Vortos\Security\Contract\SecretsInterface;
use Vortos\Security\Encryption\EncryptionKeyring;
use Vortos\Security\Encryption\EncryptionService;

final class EncryptionServiceTest extends TestCase
{
    private string $keyA;
    private string $keyB;

    protected function setUp(): void
    {
        $this->keyA = base64_encode(random_bytes(32));
        $this->keyB = base64_encode(random_bytes(32));
    }

    public function test_round_trip(): void
    {
        $service = $this->service($this->keyA);

        $ciphertext = $service->encrypt('JBSWY3DPEHPK3PXP', 'user.totp:42');

        self::assertSame('JBSWY3DPEHPK3PXP', $service->decrypt($ciphertext, 'user.totp:42'));
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', (string) base64_decode($ciphertext, true));
        self::assertFalse($service->needsReencryption($ciphertext));
    }

    public function test_each_encryption_uses_a_fresh_nonce(): void
    {
        $service = $this->service($this->keyA);

        self::assertNotSame($service->encrypt('same', 'ctx'), $service->encrypt('same', 'ctx'));
    }

    public function test_a_ciphertext_moved_to_another_context_does_not_decrypt(): void
    {
        $service    = $this->service($this->keyA);
        $ciphertext = $service->encrypt('secret', 'user.totp:alice');

        $this->expectException(\RuntimeException::class);
        $service->decrypt($ciphertext, 'user.totp:mallory');
    }

    public function test_a_changed_byte_does_not_decrypt(): void
    {
        $service = $this->service($this->keyA);
        $binary  = (string) base64_decode($service->encrypt('secret', 'ctx'), true);

        foreach ([1, 6, 20, strlen($binary) - 1] as $offset) {
            $tampered          = $binary;
            $tampered[$offset] = chr(ord($tampered[$offset]) ^ 0x01);

            try {
                $service->decrypt(base64_encode($tampered), 'ctx');
                self::fail('A ciphertext changed at byte ' . $offset . ' decrypted.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rotation_keeps_old_values_readable_and_names_them_for_re_encryption(): void
    {
        $underA = $this->service($this->keyA)->encrypt('secret', 'ctx');

        $rotated = $this->service($this->keyB, $this->keyA);
        self::assertSame('secret', $rotated->decrypt($underA, 'ctx'));
        self::assertTrue($rotated->needsReencryption($underA));

        $underB = $rotated->encrypt($rotated->decrypt($underA, 'ctx'), 'ctx');
        self::assertFalse($rotated->needsReencryption($underB));

        // Once every value is re-encrypted the retired key can go.
        self::assertSame('secret', $this->service($this->keyB)->decrypt($underB, 'ctx'));
    }

    public function test_a_value_under_a_key_no_longer_held_names_the_missing_key(): void
    {
        $underA = $this->service($this->keyA)->encrypt('secret', 'ctx');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not hold');
        $this->service($this->keyB)->decrypt($underA, 'ctx');
    }

    public function test_v1_ciphertexts_still_decrypt_and_always_need_re_encryption(): void
    {
        $v1 = $this->legacyV1('secret', 'ctx', $this->keyA);

        $service = $this->service($this->keyA);
        self::assertSame('secret', $service->decrypt($v1, 'ctx'));
        self::assertTrue($service->needsReencryption($v1));

        // Under a retired key too: v1 has no key id, so each held key is tried.
        self::assertSame('secret', $this->service($this->keyB, $this->keyA)->decrypt($v1, 'ctx'));
    }

    public function test_the_key_id_is_authenticated(): void
    {
        $service = $this->service($this->keyB, $this->keyA);
        $binary  = (string) base64_decode($service->encrypt('secret', 'ctx'), true);

        // Relabel a value as made by the retired key: the header is AAD, so the tag fails.
        $relabelled = "\x02" . EncryptionKeyring::keyIdOf((string) base64_decode($this->keyA, true)) . substr($binary, 5);

        $this->expectException(\RuntimeException::class);
        $service->decrypt(base64_encode($relabelled), 'ctx');
    }

    public function test_a_missing_or_malformed_key_is_refused(): void
    {
        foreach (['', base64_encode(random_bytes(16)), 'not base64!'] as $bad) {
            try {
                $this->service($bad)->encrypt('x', 'ctx');
                self::fail('Accepted the key ' . var_export($bad, true));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_same_key_listed_twice_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lists a key twice');
        $this->service($this->keyA, $this->keyA)->encrypt('x', 'ctx');
    }

    public function test_garbage_is_refused_not_guessed(): void
    {
        $service = $this->service($this->keyA);

        foreach (['', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', base64_encode("\x02abc")] as $garbage) {
            try {
                $service->needsReencryption($garbage);
                self::fail('Accepted ' . var_export($garbage, true));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_aes_256_gcm_is_accepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncryptionService($this->keyring($this->keyA), 'aes-256-cbc');
    }

    private function service(string $active, string ...$retired): EncryptionService
    {
        return new EncryptionService($this->keyring($active, ...$retired));
    }

    private function keyring(string $active, string ...$retired): EncryptionKeyring
    {
        $secrets = new class (['ACTIVE' => $active, 'RETIRED' => implode(',', $retired)]) implements SecretsInterface {
            /** @param array<string, string> $values */
            public function __construct(private readonly array $values) {}

            public function get(string $key): string
            {
                return $this->values[$key] ?? '';
            }
        };

        return new EncryptionKeyring('ACTIVE', $secrets, 'RETIRED');
    }

    /** The format alpha-436 and earlier wrote: fixed prefix, no key id, AAD = context. */
    private function legacyV1(string $plaintext, string $context, string $encodedKey): string
    {
        $key   = hash_hkdf('sha256', (string) base64_decode($encodedKey, true), 32, 'vortos-encryption:' . $context, '');
        $nonce = random_bytes(12);
        $tag   = '';
        $ct    = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);

        return base64_encode("\x01\x00\x00\x00" . $nonce . $tag . $ct);
    }
}
