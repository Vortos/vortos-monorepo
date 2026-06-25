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
