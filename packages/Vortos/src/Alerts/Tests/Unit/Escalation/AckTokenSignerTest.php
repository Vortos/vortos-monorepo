<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Escalation;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Escalation\AckTokenException;
use Vortos\Alerts\Escalation\AckTokenSigner;

final class AckTokenSignerTest extends TestCase
{
    public function test_valid_token_is_accepted(): void
    {
        $signer = new AckTokenSigner('a-very-secret-key');
        $now = new DateTimeImmutable();

        $token = $signer->issue('fp-1', 0, $now, 900);
        $payload = $signer->verify($token, $now);

        self::assertSame('fp-1', $payload->fingerprint);
        self::assertSame(0, $payload->tier);
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $signer = new AckTokenSigner('key');
        $now = new DateTimeImmutable();
        $token = $signer->issue('fp-1', 0, $now);

        [$payload, $signature] = explode('.', $token, 2);
        $tampered = $payload . 'x' . '.' . $signature;

        $this->expectException(AckTokenException::class);
        $signer->verify($tampered, $now);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $signer = new AckTokenSigner('key');
        $now = new DateTimeImmutable();
        $token = $signer->issue('fp-1', 0, $now);

        [$payload, $signature] = explode('.', $token, 2);
        $tampered = $payload . '.' . strrev($signature);

        $this->expectException(AckTokenException::class);
        $signer->verify($tampered, $now);
    }

    public function test_expired_token_is_rejected(): void
    {
        $signer = new AckTokenSigner('key');
        $now = new DateTimeImmutable();
        $token = $signer->issue('fp-1', 0, $now, 10);

        $this->expectException(AckTokenException::class);
        $signer->verify($token, $now->modify('+11 seconds'));
    }

    public function test_wrong_key_is_rejected(): void
    {
        $signer = new AckTokenSigner('key-a');
        $other = new AckTokenSigner('key-b');
        $now = new DateTimeImmutable();
        $token = $signer->issue('fp-1', 0, $now);

        $this->expectException(AckTokenException::class);
        $other->verify($token, $now);
    }

    public function test_replayed_token_is_still_structurally_valid_but_caller_must_dedupe(): void
    {
        // The signer itself is stateless (no nonce store) — replay protection within
        // the validity window is the AckStore's job (idempotent record-by-fingerprint).
        // This test documents that a non-expired token verifies again, by design.
        $signer = new AckTokenSigner('key');
        $now = new DateTimeImmutable();
        $token = $signer->issue('fp-1', 0, $now);

        $first = $signer->verify($token, $now);
        $second = $signer->verify($token, $now->modify('+1 second'));

        self::assertSame($first->fingerprint, $second->fingerprint);
    }

    public function test_malformed_token_is_rejected(): void
    {
        $signer = new AckTokenSigner('key');

        $this->expectException(AckTokenException::class);
        $signer->verify('not-a-valid-token', new DateTimeImmutable());
    }

    public function test_empty_hmac_key_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AckTokenSigner('');
    }
}
