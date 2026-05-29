<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Webhook;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Http\Request;
use Vortos\Paddle\Exception\WebhookIpException;
use Vortos\Paddle\Exception\WebhookReplayException;
use Vortos\Paddle\Exception\WebhookSignatureException;
use Vortos\Paddle\Webhook\PaddleWebhookController;
use Vortos\Paddle\Webhook\PaddleWebhookDispatcher;
use Vortos\Paddle\Webhook\WebhookEventFactory;
use Vortos\Paddle\Webhook\WebhookIdempotencyStore;
use Vortos\Paddle\Webhook\WebhookIpGuard;
use Vortos\Paddle\Webhook\WebhookVerifierInterface;

final class PaddleWebhookControllerTest extends TestCase
{
    private const SECRET = 'test_secret';

    private function makeSignatureHeader(string $body): string
    {
        $ts = time();
        $h1 = hash_hmac('sha256', $ts . ':' . $body, self::SECRET);
        return sprintf('ts=%d;h1=%s', $ts, $h1);
    }

    private function passingVerifier(): WebhookVerifierInterface
    {
        return new class implements WebhookVerifierInterface {
            public function verify(string $rawBody, string $signatureHeader): void {}
        };
    }

    private function failingVerifier(string $exception): WebhookVerifierInterface
    {
        return new class($exception) implements WebhookVerifierInterface {
            public function __construct(private readonly string $exceptionClass) {}

            public function verify(string $rawBody, string $signatureHeader): void
            {
                throw new $this->exceptionClass('Test failure');
            }
        };
    }

    private function makeIdempotencyStore(bool $alreadyProcessed = false): WebhookIdempotencyStore
    {
        $connection = $this->createMock(Connection::class);

        if (!$alreadyProcessed) {
            $connection->method('fetchOne')->willReturn('0');
            $connection->method('executeStatement')->willReturn(1);
        } else {
            $connection->method('fetchOne')->willReturn('1');
        }

        return new WebhookIdempotencyStore($connection, 'paddle_webhook_idempotency', 259200);
    }

    private function makeController(
        ?WebhookVerifierInterface $verifier = null,
        bool                      $ipAllowlistEnabled = false,
        bool                      $alreadyProcessed = false,
    ): PaddleWebhookController {
        return new PaddleWebhookController(
            verifier: $verifier ?? $this->passingVerifier(),
            ipGuard: new WebhookIpGuard(enabled: $ipAllowlistEnabled, allowSandboxIps: false),
            idempotencyStore: $this->makeIdempotencyStore($alreadyProcessed),
            eventFactory: new WebhookEventFactory(),
            dispatcher: new PaddleWebhookDispatcher([], new NullLogger()),
            logger: new NullLogger(),
            webhookPath: '/webhooks/paddle',
        );
    }

    private function makeRequest(array $body, string $signatureHeader = ''): Request
    {
        $json    = json_encode($body);
        $request = Request::create('/webhooks/paddle', 'POST', content: $json);
        if ($signatureHeader !== '') {
            $request->headers->set('Paddle-Signature', $signatureHeader);
        }
        return $request;
    }

    private function validPayload(): array
    {
        return [
            'event_id'        => 'evt_01',
            'notification_id' => 'ntf_01',
            'event_type'      => 'subscription.created',
            'occurred_at'     => '2024-06-01T12:00:00.000000Z',
            'data'            => ['id' => 'sub_01'],
        ];
    }

    public function test_valid_request_returns_200(): void
    {
        $response = $this->makeController()->__invoke($this->makeRequest($this->validPayload()));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_invalid_signature_returns_400(): void
    {
        $verifier = $this->failingVerifier(WebhookSignatureException::class);
        $response = $this->makeController(verifier: $verifier)->__invoke($this->makeRequest($this->validPayload()));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_replay_detected_returns_409(): void
    {
        $verifier = $this->failingVerifier(WebhookReplayException::class);
        $response = $this->makeController(verifier: $verifier)->__invoke($this->makeRequest($this->validPayload()));
        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_ip_not_allowed_returns_401(): void
    {
        $response = $this->makeController(ipAllowlistEnabled: true)
            ->__invoke($this->makeRequest($this->validPayload()));
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_duplicate_event_returns_200_without_re_dispatch(): void
    {
        $dispatched = false;
        $verifier   = $this->passingVerifier();
        $ipGuard    = new WebhookIpGuard(enabled: false, allowSandboxIps: false);
        $idempotency = $this->makeIdempotencyStore(alreadyProcessed: true);

        $handler = new class($dispatched) implements \Vortos\Paddle\Webhook\PaddleWebhookHandlerInterface {
            public function __construct(public bool &$dispatched) {}
            public function handles(): string { return 'subscription.created'; }
            public function handle(\Vortos\Paddle\Webhook\Event\PaddleWebhookEvent $event): void { $this->dispatched = true; }
        };

        $controller = new PaddleWebhookController(
            verifier: $verifier,
            ipGuard: $ipGuard,
            idempotencyStore: $idempotency,
            eventFactory: new WebhookEventFactory(),
            dispatcher: new PaddleWebhookDispatcher([$handler], new NullLogger()),
            logger: new NullLogger(),
            webhookPath: '/webhooks/paddle',
        );

        $response = $controller->__invoke($this->makeRequest($this->validPayload()));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($dispatched);
    }

    public function test_invalid_json_body_returns_400(): void
    {
        $controller = $this->makeController();
        $request    = Request::create('/webhooks/paddle', 'POST', content: 'not-json');
        $response   = $controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_handler_is_called_for_matching_event(): void
    {
        $called  = false;
        $handler = new class($called) implements \Vortos\Paddle\Webhook\PaddleWebhookHandlerInterface {
            public function __construct(public bool &$called) {}
            public function handles(): string { return 'subscription.created'; }
            public function handle(\Vortos\Paddle\Webhook\Event\PaddleWebhookEvent $event): void { $this->called = true; }
        };

        $controller = new PaddleWebhookController(
            verifier: $this->passingVerifier(),
            ipGuard: new WebhookIpGuard(enabled: false, allowSandboxIps: false),
            idempotencyStore: $this->makeIdempotencyStore(),
            eventFactory: new WebhookEventFactory(),
            dispatcher: new PaddleWebhookDispatcher([$handler], new NullLogger()),
            logger: new NullLogger(),
            webhookPath: '/webhooks/paddle',
        );

        $controller->__invoke($this->makeRequest($this->validPayload()));
        $this->assertTrue($called);
    }
}
