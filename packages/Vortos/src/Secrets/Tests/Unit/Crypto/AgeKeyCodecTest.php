<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Crypto\AgeKeyCodec;

/**
 * B5: the codec accepts both standard age formats and raw base64, and both encodings of the same
 * key decode to identical raw bytes. Invalid input fails closed.
 */
final class AgeKeyCodecTest extends TestCase
{
    private const AGE_PUBLIC = 'age1fjx9jmd35vr672vdl74t7knz3rdpmqle7f92uvyd7tdqjyv7nu3qg0h6s0';
    private const AGE_SECRET = 'AGE-SECRET-KEY-1GV5Z3TGJDGWMZHH766KMSDWFMVKHKS4GW4JNZE0W7HG5DY40NRHQYG39C2';

    public function test_decodes_age_public_key_to_32_bytes(): void
    {
        self::assertSame(32, strlen(AgeKeyCodec::decodePublicKey(self::AGE_PUBLIC)));
    }

    public function test_decodes_age_identity_to_32_bytes(): void
    {
        self::assertSame(32, strlen(AgeKeyCodec::decodeIdentity(self::AGE_SECRET)));
    }

    public function test_bech32_and_base64_public_key_are_equivalent(): void
    {
        $fromBech32 = AgeKeyCodec::decodePublicKey(self::AGE_PUBLIC);
        $fromBase64 = AgeKeyCodec::decodePublicKey(base64_encode($fromBech32));

        self::assertTrue(hash_equals($fromBech32, $fromBase64));
    }

    public function test_bech32_and_base64_identity_are_equivalent(): void
    {
        $fromBech32 = AgeKeyCodec::decodeIdentity(self::AGE_SECRET);
        $fromBase64 = AgeKeyCodec::decodeIdentity(base64_encode($fromBech32));

        self::assertTrue(hash_equals($fromBech32, $fromBase64));
    }

    public function test_surrounding_whitespace_is_tolerated(): void
    {
        self::assertSame(32, strlen(AgeKeyCodec::decodePublicKey("  " . self::AGE_PUBLIC . "\n")));
    }

    public function test_public_key_with_identity_hrp_is_rejected(): void
    {
        // A well-formed bech32 string with the wrong prefix must not be accepted as a public key.
        $this->expectException(InvalidArgumentException::class);
        AgeKeyCodec::decodePublicKey(self::AGE_SECRET);
    }

    public function test_garbage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgeKeyCodec::decodePublicKey('definitely not a key !!!');
    }
}
