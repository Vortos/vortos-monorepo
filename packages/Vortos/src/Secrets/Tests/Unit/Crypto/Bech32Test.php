<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Crypto\Bech32;

/**
 * B5: the bech32 decoder underpinning age-key support. Covers a canonical BIP-173 vector, real age
 * key material, and fail-closed rejection of corrupted / mixed-case / out-of-charset input.
 */
final class Bech32Test extends TestCase
{
    // A real age keypair (generated with `age-keygen`); the secret's X25519 public equals the pub.
    private const AGE_PUBLIC = 'age1fjx9jmd35vr672vdl74t7knz3rdpmqle7f92uvyd7tdqjyv7nu3qg0h6s0';
    private const AGE_SECRET = 'AGE-SECRET-KEY-1GV5Z3TGJDGWMZHH766KMSDWFMVKHKS4GW4JNZE0W7HG5DY40NRHQYG39C2';

    public function test_decodes_bip173_reference_vector(): void
    {
        $decoded = Bech32::decode('A12UEL5L');
        self::assertSame('a', $decoded['hrp']);
        self::assertSame('', $decoded['bytes']);
    }

    public function test_decodes_real_age_public_key_to_32_bytes(): void
    {
        $decoded = Bech32::decode(self::AGE_PUBLIC);
        self::assertSame('age', $decoded['hrp']);
        self::assertSame(32, strlen($decoded['bytes']));
    }

    public function test_decodes_real_age_secret_key_to_32_bytes(): void
    {
        $decoded = Bech32::decode(self::AGE_SECRET);
        self::assertSame('age-secret-key-', $decoded['hrp']);
        self::assertSame(32, strlen($decoded['bytes']));
    }

    public function test_secret_scalar_derives_the_public_key(): void
    {
        $pub = Bech32::decode(self::AGE_PUBLIC)['bytes'];
        $scalar = Bech32::decode(self::AGE_SECRET)['bytes'];

        self::assertTrue(hash_equals($pub, sodium_crypto_box_publickey_from_secretkey($scalar)));
    }

    public function test_rejects_corrupted_checksum(): void
    {
        // Flip one character of the data part — the checksum must fail.
        $corrupted = substr(self::AGE_PUBLIC, 0, -1) . (self::AGE_PUBLIC[-1] === 'q' ? 'p' : 'q');

        $this->expectException(InvalidArgumentException::class);
        Bech32::decode($corrupted);
    }

    public function test_rejects_mixed_case(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Bech32::decode('age1FJx9jmd35vr672vdl74t7knz3rdpmqle7f92uvyd7tdqjyv7nu3qg0h6s0');
    }

    public function test_rejects_invalid_charset_character(): void
    {
        // 'b', 'i', 'o' and '1' are excluded from the bech32 data charset.
        $this->expectException(InvalidArgumentException::class);
        Bech32::decode('age1bbbbbbbb');
    }
}
