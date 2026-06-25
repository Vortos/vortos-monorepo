<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Crypto;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Crypto\SecretEnvelope;

final class SecretEnvelopeTest extends TestCase
{
    public function test_rejects_schema_version_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecretEnvelope(0, 'ct', str_repeat("\x00", 24), 'aad', ['r' => 'w'], new DateTimeImmutable());
    }

    public function test_rejects_wrong_nonce_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecretEnvelope(1, 'ct', 'too-short', 'aad', ['r' => 'w'], new DateTimeImmutable());
    }

    public function test_rejects_empty_wrapped_deks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecretEnvelope(1, 'ct', str_repeat("\x00", 24), 'aad', [], new DateTimeImmutable());
    }

    public function test_rejects_empty_wrapped_dek_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecretEnvelope(1, 'ct', str_repeat("\x00", 24), 'aad', ['r' => ''], new DateTimeImmutable());
    }

    /**
     * Pinned format vector (§ Block 5 plan, "TCK ships negative cases... pinned
     * crypto vector"): a fixed envelope MUST always serialize to this exact
     * base64/JSON shape. If this test ever needs to change, the on-disk envelope
     * format has changed and every existing encrypted secret must be considered
     * for re-encryption — that is a deliberate, reviewed decision, never a silent
     * side effect of refactoring.
     */
    public function test_pinned_canonical_serialization_vector(): void
    {
        $envelope = new SecretEnvelope(
            1,
            "\x01\x02\x03\x04",
            str_repeat("\x05", 24),
            'vortos-secrets-envelope-v1',
            ['zzz' => "\x09\x0a", 'aaa' => "\x07\x08"],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        self::assertSame([
            'schemaVersion' => 1,
            'aeadCiphertext' => 'AQIDBA==',
            'nonce' => 'BQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUF',
            'aad' => 'vortos-secrets-envelope-v1',
            // key-sorted regardless of construction order
            'wrappedDeks' => ['aaa' => 'Bwg=', 'zzz' => 'CQo='],
            'createdAt' => '2026-01-01T00:00:00+00:00',
        ], $envelope->toArray());
    }

    public function test_array_round_trip(): void
    {
        $envelope = new SecretEnvelope(
            1,
            random_bytes(48),
            random_bytes(24),
            'vortos-secrets-envelope-v1',
            ['primary' => random_bytes(48)],
            new DateTimeImmutable('2026-06-23T00:00:00+00:00'),
        );

        $restored = SecretEnvelope::fromArray($envelope->toArray());

        self::assertSame($envelope->toArray(), $restored->toArray());
    }

    public function test_with_wrapped_dek_adds_recipient_without_mutating_original(): void
    {
        $envelope = new SecretEnvelope(1, 'ct', str_repeat("\x00", 24), 'aad', ['a' => 'wa'], new DateTimeImmutable());

        $withB = $envelope->withWrappedDek('b', 'wb');

        self::assertArrayNotHasKey('b', $envelope->wrappedDeks);
        self::assertSame('wb', $withB->wrappedDeks['b']);
        self::assertSame('wa', $withB->wrappedDeks['a']);
    }

    public function test_without_recipient_removes_one_recipient(): void
    {
        $envelope = new SecretEnvelope(1, 'ct', str_repeat("\x00", 24), 'aad', ['a' => 'wa', 'b' => 'wb'], new DateTimeImmutable());

        $withoutB = $envelope->withoutRecipient('b');

        self::assertArrayHasKey('a', $withoutB->wrappedDeks);
        self::assertArrayNotHasKey('b', $withoutB->wrappedDeks);
    }
}
