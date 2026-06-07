<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Exception\WebhookReplayException;
use Vortos\Paddle\Exception\WebhookSignatureException;
use Vortos\Paddle\Webhook\WebhookVerifier;

final class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'pdl_ntfset_test_secret_abc123';

    private function makeSignatureHeader(string $body, string $secret, int $ts): string
    {
        $payload = $ts . ':' . $body;
        $h1      = hash_hmac('sha256', $payload, $secret);
        return sprintf('ts=%d;h1=%s', $ts, $h1);
    }

    private function makeVerifier(int $replayWindowSeconds = 300, string|array $secret = self::SECRET): WebhookVerifier
    {
        return new WebhookVerifier($secret, $replayWindowSeconds);
    }

    public function test_valid_signature_passes(): void
    {
        $body   = '{"event_type":"subscription.created","event_id":"evt_01"}';
        $ts     = time();
        $header = $this->makeSignatureHeader($body, self::SECRET, $ts);

        $this->makeVerifier()->verify($body, $header);
        $this->assertTrue(true);
    }

    public function test_missing_header_throws(): void
    {
        $this->expectException(WebhookSignatureException::class);
        $this->makeVerifier()->verify('body', '');
    }

    public function test_malformed_header_missing_ts_throws(): void
    {
        $this->expectException(WebhookSignatureException::class);
        $this->makeVerifier()->verify('body', 'h1=abcdef');
    }

    public function test_malformed_header_missing_h1_throws(): void
    {
        $this->expectException(WebhookSignatureException::class);
        $this->makeVerifier()->verify('body', sprintf('ts=%d', time()));
    }

    public function test_invalid_hmac_throws(): void
    {
        $body   = '{"event_type":"subscription.created"}';
        $ts     = time();
        $header = sprintf('ts=%d;h1=%s', $ts, str_repeat('0', 64));

        $this->expectException(WebhookSignatureException::class);
        $this->makeVerifier()->verify($body, $header);
    }

    public function test_expired_timestamp_positive_offset_throws(): void
    {
        $body = 'body';
        $ts   = time() - 10; // 10 seconds ago
        $header = $this->makeSignatureHeader($body, self::SECRET, $ts);

        $this->expectException(WebhookReplayException::class);
        $this->makeVerifier(replayWindowSeconds: 5)->verify($body, $header);
    }

    public function test_expired_timestamp_negative_offset_throws(): void
    {
        $body   = 'body';
        $ts     = time() + 10; // 10 seconds in future (clock skew)
        $header = $this->makeSignatureHeader($body, self::SECRET, $ts);

        $this->expectException(WebhookReplayException::class);
        $this->makeVerifier(replayWindowSeconds: 5)->verify($body, $header);
    }

    public function test_timestamp_within_window_passes(): void
    {
        $body   = 'body';
        $ts     = time() - 4; // 4 seconds ago, within 5s window
        $header = $this->makeSignatureHeader($body, self::SECRET, $ts);

        $this->makeVerifier(replayWindowSeconds: 5)->verify($body, $header);
        $this->assertTrue(true);
    }

    public function test_secret_rotation_accepts_current_secret(): void
    {
        $body    = 'body';
        $ts      = time();
        $current = 'current_secret';
        $old     = 'old_secret';
        $header  = $this->makeSignatureHeader($body, $current, $ts);

        $this->makeVerifier(secret: [$current, $old])->verify($body, $header);
        $this->assertTrue(true);
    }

    public function test_secret_rotation_accepts_old_secret_during_transition(): void
    {
        $body    = 'body';
        $ts      = time();
        $current = 'current_secret';
        $old     = 'old_secret';
        $header  = $this->makeSignatureHeader($body, $old, $ts);

        $this->makeVerifier(secret: [$current, $old])->verify($body, $header);
        $this->assertTrue(true);
    }

    public function test_secret_rotation_rejects_unknown_secret(): void
    {
        $body   = 'body';
        $ts     = time();
        $header = $this->makeSignatureHeader($body, 'unknown_secret', $ts);

        $this->expectException(WebhookSignatureException::class);
        $this->makeVerifier(secret: ['current', 'old'])->verify($body, $header);
    }

    public function test_tampered_body_throws(): void
    {
        $original = '{"event_type":"subscription.created"}';
        $ts       = time();
        $header   = $this->makeSignatureHeader($original, self::SECRET, $ts);

        $tampered = '{"event_type":"transaction.paid"}';
        $this->expectException(WebhookSignatureException::class);
        $this->makeVerifier()->verify($tampered, $header);
    }
}
