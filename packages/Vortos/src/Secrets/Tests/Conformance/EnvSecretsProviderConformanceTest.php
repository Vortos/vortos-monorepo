<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Conformance;

use Vortos\Secrets\Crypto\EnvelopeCipher;
use Vortos\Secrets\Driver\Age\AgeKeyProvider;
use Vortos\Secrets\Driver\Env\EnvSecretsProvider;
use Vortos\Secrets\Driver\File\FileSecretStore;
use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Secrets\Testing\SecretsProviderConformanceTestCase;

final class EnvSecretsProviderConformanceTest extends SecretsProviderConformanceTestCase
{
    private const ENV_VAR = 'VORTOS_SECRETS_AGE_IDENTITY_TCK_PROVIDER';

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

    protected function createProvider(): SecretsProviderInterface
    {
        $path = sys_get_temp_dir() . '/vortos-secrets-tck-' . bin2hex(random_bytes(8)) . '.enc.json';

        return new EnvSecretsProvider(
            new FileSecretStore($path),
            new AgeKeyProvider(self::$publicKeyBase64, self::ENV_VAR),
            new EnvelopeCipher(),
        );
    }

    protected function expectedKey(): string
    {
        return 'env';
    }
}
