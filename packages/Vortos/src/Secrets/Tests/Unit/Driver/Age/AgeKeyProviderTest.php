<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Driver\Age;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Driver\Age\AgeKeyProvider;
use Vortos\Secrets\Exception\KeyUnavailableException;
use Vortos\Secrets\Key\DataKey;

final class AgeKeyProviderTest extends TestCase
{
    private const ENV_VAR = 'VORTOS_SECRETS_AGE_IDENTITY_UNIT_TEST';

    protected function tearDown(): void
    {
        putenv(self::ENV_VAR);
    }

    // A real age keypair (generated with `age-keygen`).
    private const AGE_PUBLIC = 'age1fjx9jmd35vr672vdl74t7knz3rdpmqle7f92uvyd7tdqjyv7nu3qg0h6s0';
    private const AGE_SECRET = 'AGE-SECRET-KEY-1GV5Z3TGJDGWMZHH766KMSDWFMVKHKS4GW4JNZE0W7HG5DY40NRHQYG39C2';

    public function test_seals_with_age_recipient_and_unseals_with_age_identity(): void
    {
        // B5: the standard age formats round-trip end to end — `secrets:set` seals to an `age1…`
        // recipient, the deploy unseals with the `AGE-SECRET-KEY-1…` identity from the KEK env var.
        $provider = new AgeKeyProvider(self::AGE_PUBLIC, self::ENV_VAR);
        putenv(self::ENV_VAR . '=' . self::AGE_SECRET);

        $dataKey = DataKey::fromRaw(random_bytes(32));
        $wrapped = $provider->wrap($dataKey);
        $unwrapped = $provider->unwrap($wrapped);

        self::assertSame(
            bin2hex($dataKey->revealForEncryption()),
            bin2hex($unwrapped->revealForEncryption()),
        );
    }

    public function test_rejects_invalid_public_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgeKeyProvider('not-valid-base64!!!', self::ENV_VAR);
    }

    public function test_rejects_wrong_length_public_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgeKeyProvider(base64_encode('too-short'), self::ENV_VAR);
    }

    public function test_unwrap_without_configured_identity_fails_closed(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $publicKey = base64_encode(sodium_crypto_box_publickey_from_secretkey($seed));
        $provider = new AgeKeyProvider($publicKey, self::ENV_VAR);

        putenv(self::ENV_VAR);

        $wrapped = $provider->wrap(DataKey::fromRaw(random_bytes(32)));

        $this->expectException(KeyUnavailableException::class);
        $provider->unwrap($wrapped);
    }

    public function test_unwrap_with_malformed_identity_fails_closed(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $publicKey = base64_encode(sodium_crypto_box_publickey_from_secretkey($seed));
        $provider = new AgeKeyProvider($publicKey, self::ENV_VAR);

        putenv(self::ENV_VAR . '=not-a-valid-seed');

        $wrapped = $provider->wrap(DataKey::fromRaw(random_bytes(32)));

        $this->expectException(KeyUnavailableException::class);
        $provider->unwrap($wrapped);
    }

    public function test_unwrap_with_wrong_identity_fails_closed(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $publicKey = base64_encode(sodium_crypto_box_publickey_from_secretkey($seed));
        $provider = new AgeKeyProvider($publicKey, self::ENV_VAR);

        $wrapped = $provider->wrap(DataKey::fromRaw(random_bytes(32)));

        $wrongSeed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        putenv(self::ENV_VAR . '=' . base64_encode($wrongSeed));

        $this->expectException(\Vortos\Secrets\Exception\DecryptionFailedException::class);
        $provider->unwrap($wrapped);
    }
}
