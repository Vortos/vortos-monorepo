<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Webhook;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Http\Request;
use Vortos\AwsSes\Bounce\BounceHandlerRunner;
use Vortos\AwsSes\Bounce\ComplaintHandlerRunner;
use Vortos\AwsSes\Contract\BounceHandlerInterface;
use Vortos\AwsSes\Contract\ComplaintHandlerInterface;
use Vortos\AwsSes\Exception\WebhookVerificationException;
use Vortos\AwsSes\Webhook\BounceNotification;
use Vortos\AwsSes\Webhook\ComplaintNotification;
use Vortos\AwsSes\Webhook\SignatureVerifierInterface;
use Vortos\AwsSes\Webhook\SnsWebhookController;

final class SnsWebhookControllerTest extends TestCase
{
    private function passingVerifier(): SignatureVerifierInterface
    {
        return new class implements SignatureVerifierInterface {
            public function verify(array $payload): void {}
        };
    }

    private function failingVerifier(): SignatureVerifierInterface
    {
        return new class implements SignatureVerifierInterface {
            public function verify(array $payload): void
            {
                throw WebhookVerificationException::invalidSignature();
            }
        };
    }

    private function makeController(
        bool $verificationPasses = true,
        ?BounceHandlerInterface $bounceHandler = null,
        ?ComplaintHandlerInterface $complaintHandler = null,
        int $maxBodyBytes = 65536,
    ): SnsWebhookController {
        $verifier        = $verificationPasses ? $this->passingVerifier() : $this->failingVerifier();
        $bounceRunner    = new BounceHandlerRunner($bounceHandler ? [$bounceHandler] : [], new NullLogger());
        $complaintRunner = new ComplaintHandlerRunner($complaintHandler ? [$complaintHandler] : [], new NullLogger());

        return new SnsWebhookController($verifier, $bounceRunner, $complaintRunner, new NullLogger(), $maxBodyBytes);
    }

    private function makeRequest(array $body): Request
    {
        return Request::create('/webhooks/aws/ses', 'POST', content: json_encode($body));
    }

    private function bouncePayload(): array
    {
        return [
            'Type'             => 'Notification',
            'MessageId'        => 'msg-1',
            'TopicArn'         => 'arn:aws:sns:us-east-1:123:ses',
            'SigningCertURL'   => 'https://sns.us-east-1.amazonaws.com/cert.pem',
            'Signature'        => 'abc',
            'SignatureVersion' => '1',
            'Timestamp'        => '2024-01-01T00:00:00Z',
            'Message'          => json_encode([
                'notificationType' => 'Bounce',
                'bounce' => [
                    'bounceType'    => 'Permanent',
                    'bounceSubType' => 'General',
                    'feedbackId'    => 'feedback-1',
                    'timestamp'     => '2024-01-01T00:00:00+00:00',
                    'bouncedRecipients' => [
                        ['emailAddress' => 'bounce@example.com', 'diagnosticCode' => '550 User unknown'],
                    ],
                ],
            ]),
        ];
    }

    private function complaintPayload(): array
    {
        return [
            'Type'             => 'Notification',
            'MessageId'        => 'msg-2',
            'TopicArn'         => 'arn:aws:sns:us-east-1:123:ses',
            'SigningCertURL'   => 'https://sns.us-east-1.amazonaws.com/cert.pem',
            'Signature'        => 'abc',
            'SignatureVersion' => '1',
            'Timestamp'        => '2024-01-01T00:00:00Z',
            'Message'          => json_encode([
                'notificationType' => 'Complaint',
                'complaint' => [
                    'complaintFeedbackType' => 'abuse',
                    'feedbackId'            => 'feedback-2',
                    'timestamp'             => '2024-01-01T00:00:00+00:00',
                    'complainedRecipients'  => [
                        ['emailAddress' => 'complaint@example.com'],
                    ],
                ],
            ]),
        ];
    }

    public function test_returns_200_on_valid_bounce_notification(): void
    {
        $response = $this->makeController()->__invoke($this->makeRequest($this->bouncePayload()));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_bounce_handler_is_called(): void
    {
        $called  = false;
        $handler = new class($called) implements BounceHandlerInterface {
            public function __construct(public bool &$called) {}
            public function handle(BounceNotification $n): void { $this->called = true; }
        };

        $this->makeController(bounceHandler: $handler)->__invoke($this->makeRequest($this->bouncePayload()));
        $this->assertTrue($called);
    }

    public function test_complaint_handler_is_called(): void
    {
        $called  = false;
        $handler = new class($called) implements ComplaintHandlerInterface {
            public function __construct(public bool &$called) {}
            public function handle(ComplaintNotification $n): void { $this->called = true; }
        };

        $this->makeController(complaintHandler: $handler)->__invoke($this->makeRequest($this->complaintPayload()));
        $this->assertTrue($called);
    }

    public function test_failed_verification_returns_403(): void
    {
        $response = $this->makeController(verificationPasses: false)
            ->__invoke($this->makeRequest($this->bouncePayload()));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_invalid_json_body_returns_400(): void
    {
        $request  = Request::create('/webhooks/aws/ses', 'POST', content: 'not-json');
        $response = $this->makeController()->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_subscription_confirmation_returns_200(): void
    {
        $payload = [
            'Type'             => 'SubscriptionConfirmation',
            'MessageId'        => 'msg-3',
            'TopicArn'         => 'arn:aws:sns:us-east-1:123:ses',
            'SigningCertURL'   => 'https://sns.us-east-1.amazonaws.com/cert.pem',
            'Signature'        => 'abc',
            'SignatureVersion' => '1',
            'Message'          => 'confirm',
            'Timestamp'        => '2024-01-01T00:00:00Z',
            'SubscribeURL'     => 'https://sns.us-east-1.amazonaws.com/subscribe',
            'Token'            => 'token123',
        ];

        $response = $this->makeController()->__invoke($this->makeRequest($payload));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_unknown_sns_type_returns_200(): void
    {
        $payload = [
            'Type'             => 'UnsubscribeConfirmation',
            'MessageId'        => 'msg-4',
            'TopicArn'         => 'arn:aws:sns:us-east-1:123:ses',
            'SigningCertURL'   => 'https://sns.us-east-1.amazonaws.com/cert.pem',
            'Signature'        => 'abc',
            'SignatureVersion' => '1',
            'Timestamp'        => '2024-01-01T00:00:00Z',
        ];

        $response = $this->makeController()->__invoke($this->makeRequest($payload));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_bounce_with_missing_email_is_skipped(): void
    {
        $called  = false;
        $handler = new class($called) implements BounceHandlerInterface {
            public function __construct(public bool &$called) {}
            public function handle(BounceNotification $n): void { $this->called = true; }
        };

        $payload                    = $this->bouncePayload();
        $msg                        = json_decode($payload['Message'], true);
        $msg['bounce']['bouncedRecipients'] = [['emailAddress' => '']];
        $payload['Message']         = json_encode($msg);

        $this->makeController(bounceHandler: $handler)->__invoke($this->makeRequest($payload));
        $this->assertFalse($called);
    }

    public function test_invalid_message_json_returns_400(): void
    {
        $payload            = $this->bouncePayload();
        $payload['Message'] = 'not-json';

        $response = $this->makeController()->__invoke($this->makeRequest($payload));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_oversized_body_returns_413(): void
    {
        $controller = $this->makeController(maxBodyBytes: 10);
        $request    = Request::create('/webhooks/aws/ses', 'POST', content: str_repeat('x', 11));

        $response = $controller->__invoke($request);

        $this->assertSame(413, $response->getStatusCode());
    }

    public function test_body_at_exact_limit_is_accepted(): void
    {
        $controller = $this->makeController(verificationPasses: false, maxBodyBytes: 10);
        $request    = Request::create('/webhooks/aws/ses', 'POST', content: str_repeat('x', 10));

        // Verification will fail (not JSON), but not 413 — the size check passes
        $response = $controller->__invoke($request);

        $this->assertNotSame(413, $response->getStatusCode());
    }
}
