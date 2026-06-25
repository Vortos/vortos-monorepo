<?php

declare(strict_types=1);

namespace Vortos\Secrets\Testing;

use Vortos\OpsKit\Testing\ConformanceTestCase;
use Vortos\Secrets\Exception\SecretNotFoundException;
use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretValue;

/**
 * The universal {@see SecretsProviderInterface} contract — every driver, whatever
 * its storage/custody mechanism, must satisfy this. Concrete drivers extend this
 * and supply {@see createProvider()} + {@see expectedKey()}; each test below
 * therefore runs once per driver, automatically.
 */
abstract class SecretsProviderConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createProvider(): SecretsProviderInterface;

    final protected function createDriver(): SecretsProviderInterface
    {
        return $this->createProvider();
    }

    final public function test_put_then_get_round_trips(): void
    {
        $provider = $this->createProvider();
        $key = SecretKey::fromString('tck-roundtrip');
        $value = SecretValue::fromString('tck-secret-value');

        $version = $provider->put($key, $value);

        self::assertSame('tck-secret-value', $provider->get($key)->reveal());
        self::assertTrue($version->isValid());
    }

    /** Fail-closed: a missing secret must raise, never return null/empty. */
    final public function test_get_of_missing_key_fails_closed(): void
    {
        $provider = $this->createProvider();

        $this->expectException(SecretNotFoundException::class);
        $provider->get(SecretKey::fromString('tck-does-not-exist'));
    }

    final public function test_versions_of_missing_key_fails_closed(): void
    {
        $provider = $this->createProvider();

        $this->expectException(SecretNotFoundException::class);
        $provider->versions(SecretKey::fromString('tck-does-not-exist'));
    }

    final public function test_list_returns_keys_that_were_put(): void
    {
        $provider = $this->createProvider();
        $key = SecretKey::fromString('tck-listed');
        $provider->put($key, SecretValue::fromString('v'));

        $names = array_map(static fn (SecretKey $k): string => $k->value(), $provider->list());

        self::assertContains('tck-listed', $names);
    }

    final public function test_list_never_exposes_values(): void
    {
        $provider = $this->createProvider();
        $provider->put(SecretKey::fromString('tck-opaque'), SecretValue::fromString('super-secret'));

        foreach ($provider->list() as $key) {
            self::assertInstanceOf(SecretKey::class, $key);
        }
    }

    final public function test_rotate_produces_a_two_phase_result(): void
    {
        $provider = $this->createProvider();
        $key = SecretKey::fromString('tck-rotatable');
        $provider->put($key, SecretValue::fromString('v1'));

        $policy = new RotationPolicy(3600, 60, 7200);
        $result = $provider->rotate($key, $policy);

        self::assertNotNull($result->previousVersion);
        self::assertTrue($result->newVersion->isValid());
        self::assertSame($result->newVersion->versionId, $provider->versions($key)->currentVersionId);
    }

    final public function test_rotate_of_missing_key_fails_closed(): void
    {
        $provider = $this->createProvider();
        $policy = new RotationPolicy(3600, 60, 7200);

        $this->expectException(SecretNotFoundException::class);
        $provider->rotate(SecretKey::fromString('tck-does-not-exist'), $policy);
    }
}
