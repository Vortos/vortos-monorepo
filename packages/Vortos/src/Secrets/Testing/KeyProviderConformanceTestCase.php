<?php

declare(strict_types=1);

namespace Vortos\Secrets\Testing;

use Vortos\OpsKit\Testing\ConformanceTestCase;
use Vortos\Secrets\Exception\DecryptionFailedException;
use Vortos\Secrets\Key\DataKey;
use Vortos\Secrets\Key\KeyProviderCapability;
use Vortos\Secrets\Key\KeyProviderInterface;
use Vortos\Secrets\Key\WrappedKey;

/**
 * The universal {@see KeyProviderInterface} contract — wrap/unwrap round-trips,
 * and a tampered wrapped key must fail closed, regardless of custody mechanism.
 */
abstract class KeyProviderConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createKeyProvider(): KeyProviderInterface;

    final protected function createDriver(): KeyProviderInterface
    {
        return $this->createKeyProvider();
    }

    final public function test_wrap_then_unwrap_round_trips(): void
    {
        $provider = $this->createKeyProvider();
        $dataKey = DataKey::fromRaw(random_bytes(32));

        $wrapped = $provider->wrap($dataKey);
        $unwrapped = $provider->unwrap($wrapped);

        self::assertSame($dataKey->revealForEncryption(), $unwrapped->revealForEncryption());
    }

    final public function test_tampered_wrapped_key_fails_closed(): void
    {
        $provider = $this->createKeyProvider();
        $wrapped = $provider->wrap(DataKey::fromRaw(random_bytes(32)));

        $tamperedCiphertext = $wrapped->ciphertext;
        $tamperedCiphertext[0] = $tamperedCiphertext[0] === "\x00" ? "\x01" : "\x00";
        $tampered = new WrappedKey($tamperedCiphertext, $wrapped->recipientId);

        $this->expectException(DecryptionFailedException::class);
        $provider->unwrap($tampered);
    }

    final public function test_off_host_key_capability_is_declared_honestly(): void
    {
        $descriptor = $this->createKeyProvider()->capabilities();

        self::assertTrue($descriptor->supports(KeyProviderCapability::OffHostKey));
    }

    final public function test_each_wrap_of_the_same_data_key_can_still_be_unwrapped(): void
    {
        $provider = $this->createKeyProvider();
        $dataKey = DataKey::fromRaw(random_bytes(32));

        $first = $provider->wrap($dataKey);
        $second = $provider->wrap($dataKey);

        self::assertSame($dataKey->revealForEncryption(), $provider->unwrap($first)->revealForEncryption());
        self::assertSame($dataKey->revealForEncryption(), $provider->unwrap($second)->revealForEncryption());
    }
}
