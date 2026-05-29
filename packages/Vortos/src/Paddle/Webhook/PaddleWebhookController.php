<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook;

use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Paddle\Exception\WebhookIpException;
use Vortos\Paddle\Exception\WebhookReplayException;
use Vortos\Paddle\Exception\WebhookSignatureException;

#[AsController]
final class PaddleWebhookController
{
    public function __construct(
        private readonly WebhookVerifierInterface   $verifier,
        private readonly WebhookIpGuard             $ipGuard,
        private readonly WebhookIdempotencyStore    $idempotencyStore,
        private readonly WebhookEventFactory        $eventFactory,
        private readonly PaddleWebhookDispatcher    $dispatcher,
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
        } catch (WebhookIpException $e) {
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

        $eventId   = $payload['event_id'] ?? '';
        $eventType = $payload['event_type'] ?? 'unknown';

        if ($eventId !== '' && $this->idempotencyStore->hasBeenProcessed($eventId)) {
            $this->logger->debug('Paddle webhook duplicate received, acknowledging', ['event_id' => $eventId]);
            return new JsonResponse(['status' => 'ok']);
        }

        if ($eventId !== '') {
            $this->idempotencyStore->markProcessed($eventId, $eventType);
        }

        $event = $this->eventFactory->fromVerifiedPayload($payload);

        $this->dispatcher->dispatch($event);

        $this->logger->info('Paddle webhook processed', [
            'event_type' => $event->eventType,
            'event_id'   => $event->eventId,
        ]);

        return new JsonResponse(['status' => 'ok']);
    }
}
