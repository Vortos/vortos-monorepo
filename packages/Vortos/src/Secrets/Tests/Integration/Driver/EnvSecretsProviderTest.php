<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Integration\Driver;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Crypto\EnvelopeCipher;
use Vortos\Secrets\Driver\Age\AgeKeyProvider;
use Vortos\Secrets\Driver\Env\EnvSecretsProvider;
use Vortos\Secrets\Driver\File\FileSecretStore;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretValue;
use Vortos\Secrets\Value\SecretVersionState;

/**
 * Full encrypted-file round-trip with a real off-host identity (real ext-sodium,
 * real filesystem) — proves the whole stack (`env` + `age` + `FileSecretStore` +
 * `EnvelopeCipher`) composes correctly end to end, not just each layer in
 * isolation.
 */
final class EnvSecretsProviderTest extends TestCase
{
    private const ENV_VAR = 'VORTOS_SECRETS_AGE_IDENTITY_INTEGRATION';

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/vortos-secrets-integration-' . bin2hex(random_bytes(8)) . '.enc.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        putenv(self::ENV_VAR);
    }

    public function test_full_round_trip_with_off_host_identity(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $publicKey = base64_encode(sodium_crypto_box_publickey_from_secretkey($seed));
        putenv(self::ENV_VAR . '=' . base64_encode($seed));

        $provider = $this->makeProvider($publicKey);

        $key = SecretKey::fromString('database-password');
        $provider->put($key, SecretValue::fromString('correct-horse-battery-staple'));

        self::assertTrue($provider->versions($key)->current()->isValid());

        // A fresh provider instance (new process, in effect) re-derives the value
        // purely from the encrypted-at-rest file plus the off-host identity.
        $reopened = $this->makeProvider($publicKey);
        self::assertSame('correct-horse-battery-staple', $reopened->get($key)->reveal());
    }

    public function test_put_persists_ciphertext_only_on_disk(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $publicKey = base64_encode(sodium_crypto_box_publickey_from_secretkey($seed));
        putenv(self::ENV_VAR . '=' . base64_encode($seed));

        $provider = $this->makeProvider($publicKey);
        $provider->put(SecretKey::fromString('api-key'), SecretValue::fromString('sk-extremely-secret-marker'));

        $onDisk = file_get_contents($this->path);
        self::assertIsString($onDisk);
        self::assertStringNotContainsString('sk-extremely-secret-marker', $onDisk);
    }

    public function test_list_returns_names_only(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $publicKey = base64_encode(sodium_crypto_box_publickey_from_secretkey($seed));
        putenv(self::ENV_VAR . '=' . base64_encode($seed));

        $provider = $this->makeProvider($publicKey);
        $provider->put(SecretKey::fromString('secret-one'), SecretValue::fromString('v1'));
        $provider->put(SecretKey::fromString('secret-two'), SecretValue::fromString('v2'));

        $names = array_map(static fn (SecretKey $k): string => $k->value(), $provider->list());
        sort($names);

        self::assertSame(['secret-one', 'secret-two'], $names);
    }

    public function test_rotation_grace_window_old_and_new_both_valid_mid_rotation(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $publicKey = base64_encode(sodium_crypto_box_publickey_from_secretkey($seed));
        putenv(self::ENV_VAR . '=' . base64_encode($seed));

        $provider = $this->makeProvider($publicKey);
        $key = SecretKey::fromString('rotating-token');
        $provider->put($key, SecretValue::fromString('initial-token'));

        $policy = new RotationPolicy(intervalSeconds: 3600, gracePeriodSeconds: 120, maxAgeSeconds: 7200);
        $result = $provider->rotate($key, $policy);

        $metadata = $provider->versions($key);
        self::assertSame($result->newVersion->versionId, $metadata->currentVersionId);
        self::assertSame(SecretVersionState::WithinGrace, $result->previousVersion->state);
        self::assertSame(SecretVersionState::Active, $result->newVersion->state);

        // Mid-grace: both new and previous validate per RotationResult's own proof.
        $midGrace = $result->newVersion->createdAt->modify('+30 seconds');
        self::assertCount(2, $result->validVersions($midGrace));

        // Past the grace window: only the new version remains valid.
        $pastGrace = $result->newVersion->createdAt->modify('+121 seconds');
        self::assertCount(1, $result->validVersions($pastGrace));
        self::assertSame($result->newVersion, $result->validVersions($pastGrace)[0]);
    }

    private function makeProvider(string $publicKeyBase64): EnvSecretsProvider
    {
        return new EnvSecretsProvider(
            new FileSecretStore($this->path),
            new AgeKeyProvider($publicKeyBase64, self::ENV_VAR),
            new EnvelopeCipher(),
        );
    }
}
