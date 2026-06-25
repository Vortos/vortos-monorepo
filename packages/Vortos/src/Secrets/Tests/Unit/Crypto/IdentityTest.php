<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Crypto\Identity;

final class IdentityTest extends TestCase
{
    public function test_generate_produces_a_32_byte_public_key(): void
    {
        $identity = Identity::generate();

        self::assertSame(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES, strlen($identity->publicKey()));
    }

    public function test_from_secret_key_seed_derives_the_same_public_key_deterministically(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);

        $a = Identity::fromSecretKeySeed($seed);
        $b = Identity::fromSecretKeySeed($seed);

        self::assertSame($a->publicKey(), $b->publicKey());
    }

    public function test_from_secret_key_seed_rejects_wrong_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Identity::fromSecretKeySeed('too-short');
    }

    public function test_from_base64_seed_round_trips(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $base64 = base64_encode($seed);

        $fromSeed = Identity::fromSecretKeySeed($seed);
        $fromBase64 = Identity::fromBase64Seed($base64);

        self::assertSame($fromSeed->publicKey(), $fromBase64->publicKey());
    }

    public function test_from_base64_seed_rejects_invalid_base64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Identity::fromBase64Seed('not valid base64!!! ###');
    }

    public function test_public_key_base64_round_trips(): void
    {
        $identity = Identity::generate();

        self::assertSame($identity->publicKey(), base64_decode($identity->publicKeyBase64(), true));
    }

    public function test_two_generated_identities_are_different(): void
    {
        $a = Identity::generate();
        $b = Identity::generate();

        self::assertNotSame($a->publicKey(), $b->publicKey());
    }

    public function test_reveal_key_pair_for_unsealing_has_correct_length(): void
    {
        $identity = Identity::generate();

        self::assertSame(
            SODIUM_CRYPTO_BOX_SECRETKEYBYTES + SODIUM_CRYPTO_BOX_PUBLICKEYBYTES,
            strlen($identity->revealKeyPairForUnsealing()),
        );
    }
}
