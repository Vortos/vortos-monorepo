<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Webhook\WebhookPayload;

final class WebhookPayloadTest extends TestCase
{
    public function test_body_is_valid_json(): void
    {
        $payload = new WebhookPayload(
            subscriptionId: 's1',
            eventType:      'flag.enabled',
            data:           ['flag' => 'my-flag', 'enabled' => true],
            timestamp:      '2026-06-22T00:00:00+00:00',
            deliveryId:     'd1',
        );

        $body = $payload->body();
        $data = json_decode($body, true);

        $this->assertIsArray($data);
        $this->assertSame('flag.enabled', $data['event']);
        $this->assertSame('d1', $data['delivery_id']);
        $this->assertSame('my-flag', $data['data']['flag']);
    }

    public function test_sign_produces_sha256_prefixed_hmac(): void
    {
        $body   = '{"event":"flag.enabled"}';
        $secret = 'my-webhook-secret';

        $signature = WebhookPayload::sign($body, $secret);

        $this->assertStringStartsWith('sha256=', $signature);
        $this->assertSame(71, strlen($signature)); // "sha256=" + 64 hex chars
    }

    public function test_verify_signature_succeeds_with_correct_secret(): void
    {
        $body   = '{"event":"flag.enabled"}';
        $secret = 'my-webhook-secret';

        $signature = WebhookPayload::sign($body, $secret);

        $this->assertTrue(WebhookPayload::verifySignature($body, $secret, $signature));
    }

    public function test_verify_signature_fails_with_wrong_secret(): void
    {
        $body   = '{"event":"flag.enabled"}';
        $sig    = WebhookPayload::sign($body, 'correct-secret');

        $this->assertFalse(WebhookPayload::verifySignature($body, 'wrong-secret', $sig));
    }

    public function test_verify_signature_fails_with_tampered_body(): void
    {
        $body   = '{"event":"flag.enabled"}';
        $secret = 'my-webhook-secret';
        $sig    = WebhookPayload::sign($body, $secret);

        $this->assertFalse(WebhookPayload::verifySignature('{"event":"flag.disabled"}', $secret, $sig));
    }

    public function test_verify_signature_timing_safe(): void
    {
        // The implementation uses hash_equals which is timing-safe. Verify by
        // checking that a partially-matching signature still fails.
        $body   = '{"event":"test"}';
        $secret = 'secret';
        $sig    = WebhookPayload::sign($body, $secret);

        // Flip the last character
        $tampered = substr($sig, 0, -1) . ($sig[-1] === 'a' ? 'b' : 'a');

        $this->assertFalse(WebhookPayload::verifySignature($body, $secret, $tampered));
    }
}
