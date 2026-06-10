<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Http\Request;
use Vortos\Paddle\Exception\WebhookReplayException;
use Vortos\Paddle\Exception\WebhookSignatureException;
use Vortos\Paddle\Inbox\PaddleInboxWriterInterface;
use Vortos\Paddle\Webhook\PaddleWebhookController;
use Vortos\Paddle\Webhook\WebhookIpGuard;
use Vortos\Paddle\Webhook\WebhookVerifierInterface;

/** In-memory inbox: records accepts, reports duplicates by event_id. */
final class FakeInboxWriter implements PaddleInboxWriterInterface
{
    /** @var array<string, array{eventType: string, payload: array, occurredAt: ?\DateTimeImmutable}> */
    public array $accepted = [];

    public function accept(string $eventId, string $eventType, array $payload, ?\DateTimeImmutable $occurredAt): bool
    {
        if (isset($this->accepted[$eventId])) {
            return false;
        }

        $this->accepted[$eventId] = ['eventType' => $eventType, 'payload' => $payload, 'occurredAt' => $occurredAt];
        return true;
    }
}

final class PaddleWebhookControllerTest extends TestCase
{
    private FakeInboxWriter $inbox;

    protected function setUp(): void
    {
        $this->inbox = new FakeInboxWriter();
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

    private function makeController(
        ?WebhookVerifierInterface $verifier = null,
        bool                      $ipAllowlistEnabled = false,
    ): PaddleWebhookController {
        return new PaddleWebhookController(
            verifier: $verifier ?? $this->passingVerifier(),
            ipGuard: new WebhookIpGuard(enabled: $ipAllowlistEnabled, allowSandboxIps: false),
            inboxWriter: $this->inbox,
            logger: new NullLogger(),
            webhookPath: '/webhooks/paddle',
        );
    }

    private function makeRequest(array $body): Request
    {
        return Request::create('/webhooks/paddle', 'POST', content: json_encode($body));
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

    public function test_valid_request_persists_to_inbox_and_returns_200(): void
    {
        $response = $this->makeController()->__invoke($this->makeRequest($this->validPayload()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('evt_01', $this->inbox->accepted);
        $this->assertSame('subscription.created', $this->inbox->accepted['evt_01']['eventType']);
        $this->assertSame('2024-06-01 12:00:00', $this->inbox->accepted['evt_01']['occurredAt']?->format('Y-m-d H:i:s'));
    }

    public function test_duplicate_event_returns_200_with_single_inbox_row(): void
    {
        $controller = $this->makeController();
        $controller->__invoke($this->makeRequest($this->validPayload()));
        $response = $controller->__invoke($this->makeRequest($this->validPayload()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->inbox->accepted);
    }

    public function test_invalid_signature_returns_400_and_persists_nothing(): void
    {
        $verifier = $this->failingVerifier(WebhookSignatureException::class);
        $response = $this->makeController(verifier: $verifier)->__invoke($this->makeRequest($this->validPayload()));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->inbox->accepted);
    }

    public function test_replay_detected_returns_409_and_persists_nothing(): void
    {
        $verifier = $this->failingVerifier(WebhookReplayException::class);
        $response = $this->makeController(verifier: $verifier)->__invoke($this->makeRequest($this->validPayload()));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([], $this->inbox->accepted);
    }

    public function test_ip_not_allowed_returns_401_and_persists_nothing(): void
    {
        $response = $this->makeController(ipAllowlistEnabled: true)
            ->__invoke($this->makeRequest($this->validPayload()));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->inbox->accepted);
    }

    public function test_invalid_json_body_returns_400_and_persists_nothing(): void
    {
        $request  = Request::create('/webhooks/paddle', 'POST', content: 'not-json');
        $response = $this->makeController()->__invoke($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->inbox->accepted);
    }

    public function test_missing_event_id_is_still_captured_with_generated_id(): void
    {
        $payload = $this->validPayload();
        unset($payload['event_id']);

        $response = $this->makeController()->__invoke($this->makeRequest($payload));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->inbox->accepted);
        $this->assertStringStartsWith('no-event-id-', array_key_first($this->inbox->accepted));
    }

    public function test_no_handler_runs_in_the_request(): void
    {
        // The controller has no dispatcher dependency at all — this asserts the
        // architectural fact by API: constructing it requires no handlers.
        $refl = new \ReflectionClass(PaddleWebhookController::class);
        $paramTypes = array_map(
            static fn(\ReflectionParameter $p) => (string) $p->getType(),
            $refl->getConstructor()->getParameters(),
        );

        $this->assertNotContains('Vortos\Paddle\Webhook\PaddleWebhookDispatcher', $paramTypes);
    }
}
