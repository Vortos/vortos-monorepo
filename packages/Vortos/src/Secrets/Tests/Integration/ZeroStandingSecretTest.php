<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Crypto\EnvelopeCipher;
use Vortos\Secrets\Driver\Age\AgeKeyProvider;
use Vortos\Secrets\Driver\Env\EnvSecretsProvider;
use Vortos\Secrets\Driver\File\FileSecretStore;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Preflight\SecretReference;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Service\RotationManager;
use Vortos\Secrets\Service\SecretInjectionPlanner;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretValue;

/**
 * **§15.2 mandatory**: after a full put → get → rotate → inject lifecycle, the
 * only on-disk artifact is the ciphertext envelope — the plaintext marker used
 * throughout this test never appears anywhere on disk, and a {@see SecretValue}
 * obtained from the provider never renders it via any introspection path.
 */
final class ZeroStandingSecretTest extends TestCase
{
    private const ENV_VAR = 'VORTOS_SECRETS_AGE_IDENTITY_ZSS';
    private const MARKER = 'zss-marker-3f9a1c7e8b4d6f02';

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/vortos-secrets-zss-' . bin2hex(random_bytes(8)) . '.enc.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        putenv(self::ENV_VAR);
    }

    public function test_no_plaintext_persists_anywhere_after_a_full_lifecycle(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $publicKey = base64_encode(sodium_crypto_box_publickey_from_secretkey($seed));
        putenv(self::ENV_VAR . '=' . base64_encode($seed));

        $provider = new EnvSecretsProvider(
            new FileSecretStore($this->path),
            new AgeKeyProvider($publicKey, self::ENV_VAR),
            new EnvelopeCipher(),
        );

        $key = SecretKey::fromString('zss-secret');

        // put
        $provider->put($key, SecretValue::fromString(self::MARKER));
        self::assertOnDiskHasNoMarker();

        // get
        $value = $provider->get($key);
        self::assertSame(self::MARKER, $value->reveal());
        self::assertOnDiskHasNoMarker();
        self::assertNoIntrospectionPathLeaksMarker($value);

        // rotate (mints a fresh random value — the original marker version moves
        // to WithinGrace but its ciphertext-at-rest still must never decode the
        // marker outside the in-memory SecretValue)
        $policy = new RotationPolicy(intervalSeconds: 3600, gracePeriodSeconds: 60, maxAgeSeconds: 7200);
        (new RotationManager($provider))->forceRotate($key, $policy);
        self::assertOnDiskHasNoMarker();

        // inject — the only place the plan is allowed to reveal, and only into an
        // in-memory array, never to disk.
        $secondKey = SecretKey::fromString('zss-injected');
        $provider->put($secondKey, SecretValue::fromString(self::MARKER));

        $plan = (new SecretInjectionPlanner())->plan($provider, new RequiredSecrets([
            new SecretReference($secondKey),
        ]));
        $materialized = $plan->materialize();

        self::assertSame(self::MARKER, $materialized['ZSS_INJECTED']);
        self::assertOnDiskHasNoMarker();
        self::assertStringNotContainsString(self::MARKER, print_r($plan->entries, true));
    }

    private function assertOnDiskHasNoMarker(): void
    {
        self::assertTrue(is_file($this->path), 'Expected the encrypted store file to exist.');

        $onDisk = file_get_contents($this->path);
        self::assertIsString($onDisk);
        self::assertStringNotContainsString(self::MARKER, $onDisk);
        self::assertStringNotContainsString(base64_encode(self::MARKER), $onDisk);
    }

    private function assertNoIntrospectionPathLeaksMarker(SecretValue $value): void
    {
        self::assertStringNotContainsString(self::MARKER, (string) $value);
        self::assertStringNotContainsString(self::MARKER, print_r($value, true));
        self::assertStringNotContainsString(self::MARKER, var_export($value, true));
        self::assertStringNotContainsString(self::MARKER, json_encode(['secret' => $value], JSON_THROW_ON_ERROR));

        ob_start();
        var_dump($value);
        $dumped = ob_get_clean();
        self::assertIsString($dumped);
        self::assertStringNotContainsString(self::MARKER, $dumped);
    }
}
