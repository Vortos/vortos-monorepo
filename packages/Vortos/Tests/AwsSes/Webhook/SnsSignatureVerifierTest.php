<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Webhook;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\AwsSes\Exception\WebhookVerificationException;
use Vortos\AwsSes\Webhook\SnsSignatureVerifier;

final class SnsSignatureVerifierTest extends TestCase
{
    private string $privateKey;
    private string $certificate;

    protected function setUp(): void
    {
        // Generate a self-signed cert for testing
        $key  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr  = openssl_csr_new([], $key);
        $cert = openssl_csr_sign($csr, null, $key, 365);

        $privateKeyPem  = '';
        $certificatePem = '';
        openssl_pkey_export($key, $privateKeyPem);
        openssl_x509_export($cert, $certificatePem);

        $this->privateKey   = $privateKeyPem;
        $this->certificate  = $certificatePem;
    }

    private function makeVerifier(string $pem): SnsSignatureVerifier
    {
        return new SnsSignatureVerifier(
            new NullLogger(),
            static fn(string $url): string => $pem,
        );
    }

    private function signPayload(array $payload, string $type): array
    {
        // Build the canonical string the same way the verifier does
        $fields = match ($type) {
            'Notification' => array_filter(
                ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
                static fn($k) => isset($payload[$k]),
            ),
            default => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        };

        $parts = [];
        foreach ($fields as $key) {
            if (isset($payload[$key])) {
                $parts[] = $key;
                $parts[] = $payload[$key];
            }
        }
        $stringToSign = implode("\n", $parts) . "\n";

        openssl_sign($stringToSign, $signature, $this->privateKey, OPENSSL_ALGO_SHA1);
        $payload['Signature']        = base64_encode($signature);
        $payload['SignatureVersion'] = '1';

        return $payload;
    }

    private function basePayload(): array
    {
        return [
            'Type'            => 'Notification',
            'MessageId'       => 'abc-123',
            'TopicArn'        => 'arn:aws:sns:us-east-1:123456789:ses-events',
            'Message'         => '{"notificationType":"Bounce"}',
            'Timestamp'       => '2024-01-01T00:00:00.000Z',
            'SigningCertURL'  => 'https://sns.us-east-1.amazonaws.com/cert.pem',
        ];
    }

    public function test_valid_notification_signature_passes(): void
    {
        $payload = $this->signPayload($this->basePayload(), 'Notification');
        $verifier = $this->makeVerifier($this->certificate);

        $verifier->verify($payload); // no exception
        $this->assertTrue(true);
    }

    public function test_valid_subscription_confirmation_passes(): void
    {
        $payload = [
            'Type'           => 'SubscriptionConfirmation',
            'MessageId'      => 'msg-456',
            'TopicArn'       => 'arn:aws:sns:us-east-1:123:ses',
            'Message'        => 'You have chosen to subscribe to the topic.',
            'SubscribeURL'   => 'https://sns.us-east-1.amazonaws.com/confirm',
            'Timestamp'      => '2024-01-01T00:00:00.000Z',
            'Token'          => 'abc123token',
            'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/cert.pem',
        ];
        $payload  = $this->signPayload($payload, 'SubscriptionConfirmation');
        $verifier = $this->makeVerifier($this->certificate);

        $verifier->verify($payload);
        $this->assertTrue(true);
    }

    public function test_tampered_message_fails(): void
    {
        $payload = $this->signPayload($this->basePayload(), 'Notification');
        $payload['Message'] = '{"notificationType":"Tampered"}';

        $this->expectException(WebhookVerificationException::class);
        $this->makeVerifier($this->certificate)->verify($payload);
    }

    public function test_non_amazonaws_cert_url_rejected(): void
    {
        $payload = $this->basePayload();
        $payload['SigningCertURL'] = 'https://evil.com/cert.pem';

        $this->expectException(WebhookVerificationException::class);
        $this->makeVerifier($this->certificate)->verify($payload);
    }

    public function test_http_cert_url_rejected(): void
    {
        $payload = $this->basePayload();
        $payload['SigningCertURL'] = 'http://sns.us-east-1.amazonaws.com/cert.pem';

        $this->expectException(WebhookVerificationException::class);
        $this->makeVerifier($this->certificate)->verify($payload);
    }

    public function test_missing_cert_url_throws(): void
    {
        $payload = $this->basePayload();
        unset($payload['SigningCertURL']);

        $this->expectException(WebhookVerificationException::class);
        $this->makeVerifier($this->certificate)->verify($payload);
    }

    public function test_invalid_base64_signature_throws(): void
    {
        $payload = $this->basePayload();
        $payload['SigningCertURL'] = 'https://sns.us-east-1.amazonaws.com/cert.pem';
        $payload['Signature']        = 'not valid base64!!!';
        $payload['SignatureVersion'] = '1';

        $this->expectException(WebhookVerificationException::class);
        $this->makeVerifier($this->certificate)->verify($payload);
    }

    public function test_sha256_signature_version_passes(): void
    {
        $payload = $this->basePayload();

        $fields = ['Message', 'MessageId', 'Timestamp', 'TopicArn', 'Type'];
        $parts  = [];
        foreach ($fields as $key) {
            if (isset($payload[$key])) {
                $parts[] = $key;
                $parts[] = $payload[$key];
            }
        }
        $stringToSign = implode("\n", $parts) . "\n";

        openssl_sign($stringToSign, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        $payload['Signature']        = base64_encode($signature);
        $payload['SignatureVersion'] = '2';

        $this->makeVerifier($this->certificate)->verify($payload);
        $this->assertTrue(true);
    }
}
