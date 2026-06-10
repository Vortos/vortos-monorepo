<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook;

use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\UuidV7;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Paddle\Exception\WebhookIpException;
use Vortos\Paddle\Exception\WebhookReplayException;
use Vortos\Paddle\Exception\WebhookSignatureException;
use Vortos\Paddle\Inbox\PaddleInboxWriterInterface;

/**
 * Receives Paddle webhooks. The request does exactly three things:
 * verify (IP + signature), persist to the inbox, acknowledge.
 *
 * NO handler runs in the request. Once the inbox insert commits the webhook
 * is durable; PaddleInboxWorker processes it asynchronously with retries and
 * dead-lettering. "Paddle delivered it" and "we processed it" are separate
 * facts — a handler bug can no longer lose a webhook Paddle believes we took.
 *
 * Duplicate deliveries hit the UNIQUE event_id constraint and are
 * acknowledged with 200 — Paddle retries until it sees 2xx, so a duplicate
 * just means our previous 200 got lost in transit.
 */
#[AsController]
final class PaddleWebhookController
{
    public function __construct(
        private readonly WebhookVerifierInterface   $verifier,
        private readonly WebhookIpGuard             $ipGuard,
        private readonly PaddleInboxWriterInterface $inboxWriter,
        private readonly LoggerInterface            $logger,
        private readonly string                     $webhookPath,
    ) {}

    #[Route('/webhooks/paddle', name: 'vortos_paddle.webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $remoteAddr      = $request->getClientIp() ?? '';
        $rawBody         = $request->getContent();
        $signatureHeader = $request->headers->get('Paddle-Signature', '');

        try {
            $this->ipGuard->check($remoteAddr);
        } catch (WebhookIpException) {
            $this->logger->warning('Paddle webhook rejected: IP not allowed', ['ip' => $remoteAddr]);
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->verifier->verify($rawBody, $signatureHeader);
        } catch (WebhookReplayException $e) {
            $this->logger->warning('Paddle webhook rejected: replay detected', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Replay detected'], Response::HTTP_CONFLICT);
        } catch (WebhookSignatureException $e) {
            $this->logger->warning('Paddle webhook rejected: signature invalid', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($rawBody, associative: true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Paddle always sends event_id; tolerate its absence so a malformed-but-
        // verified notification is still captured rather than dropped.
        $eventId   = (string) ($payload['event_id'] ?? '');
        $eventType = (string) ($payload['event_type'] ?? 'unknown');

        if ($eventId === '') {
            $eventId = 'no-event-id-' . new UuidV7()->toRfc4122();
        }

        $occurredAt = null;
        if (isset($payload['occurred_at']) && is_string($payload['occurred_at'])) {
            try {
                $occurredAt = new \DateTimeImmutable($payload['occurred_at']);
            } catch (\Exception) {
            }
        }

        $accepted = $this->inboxWriter->accept($eventId, $eventType, $payload, $occurredAt);

        if (!$accepted) {
            $this->logger->debug('Paddle webhook duplicate received, acknowledging', ['event_id' => $eventId]);
            return new JsonResponse(['status' => 'ok']);
        }

        $this->logger->info('Paddle webhook accepted into inbox', [
            'event_type' => $eventType,
            'event_id'   => $eventId,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }
}
