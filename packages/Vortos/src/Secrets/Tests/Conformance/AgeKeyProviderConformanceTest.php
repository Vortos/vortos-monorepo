<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Conformance;

use Vortos\Secrets\Driver\Age\AgeKeyProvider;
use Vortos\Secrets\Key\KeyProviderInterface;
use Vortos\Secrets\Testing\KeyProviderConformanceTestCase;

final class AgeKeyProviderConformanceTest extends KeyProviderConformanceTestCase
{
    private const ENV_VAR = 'VORTOS_SECRETS_AGE_IDENTITY_TCK';

    private static string $publicKeyBase64;

    public static function setUpBeforeClass(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        self::$publicKeyBase64 = base64_encode(sodium_crypto_box_publickey_from_secretkey($seed));

        putenv(self::ENV_VAR . '=' . base64_encode($seed));
    }

    public static function tearDownAfterClass(): void
    {
        putenv(self::ENV_VAR);
    }

    protected function createKeyProvider(): KeyProviderInterface
    {
        return new AgeKeyProvider(self::$publicKeyBase64, self::ENV_VAR);
    }

    protected function expectedKey(): string
    {
        return 'age';
    }
}
