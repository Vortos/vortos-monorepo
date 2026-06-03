<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Webhook;

use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Http\Attribute\AsController;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\AwsSes\Bounce\BounceHandlerRunner;
use Vortos\AwsSes\Bounce\ComplaintHandlerRunner;
use Vortos\AwsSes\Exception\WebhookVerificationException;
use Vortos\AwsSes\ValueObject\EmailAddress;

/**
 * Handles SNS webhook notifications from AWS SES for bounce and complaint events.
 *
 * Expects POST requests from AWS SNS with a JSON body containing the SNS envelope.
 * The route path is configured via vortos_aws_ses.webhooks.route_path (default: /webhooks/aws/ses).
 */
#[AsController]
final class SnsWebhookController
{
    public function __construct(
        private readonly SignatureVerifierInterface $verifier,
        private readonly BounceHandlerRunner $bounceRunner,
        private readonly ComplaintHandlerRunner $complaintRunner,
        private readonly LoggerInterface $logger,
        private readonly int $maxBodyBytes = 65536,
    ) {}

    #[Route('/webhooks/aws/ses', name: 'vortos_aws_ses.sns_webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->getContent();

        if (strlen($body) > $this->maxBodyBytes) {
            $this->logger->warning('SNS webhook body exceeded size limit', [
                'limit' => $this->maxBodyBytes,
                'size'  => strlen($body),
            ]);
            return new JsonResponse(['error' => 'Payload too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $payload = json_decode($body, associative: true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->verifier->verify($payload);
        } catch (WebhookVerificationException $e) {
            $this->logger->warning('SNS webhook verification failed', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Signature verification failed'], Response::HTTP_FORBIDDEN);
        }

        $type = $payload['Type'] ?? '';

        return match ($type) {
            'SubscriptionConfirmation' => $this->handleSubscriptionConfirmation($payload),
            'Notification'             => $this->handleNotification($payload),
            default                    => new JsonResponse(['status' => 'ignored', 'type' => $type]),
        };
    }

    private function handleSubscriptionConfirmation(array $payload): JsonResponse
    {
        $this->logger->info('SNS SubscriptionConfirmation received — visit SubscribeURL to confirm', [
            'topic_arn'    => $payload['TopicArn'] ?? '',
            'subscribe_url' => $payload['SubscribeURL'] ?? '',
        ]);

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleNotification(array $payload): JsonResponse
    {
        $message = json_decode($payload['Message'] ?? '', associative: true);

        if (!is_array($message)) {
            $this->logger->error('SNS Notification Message is not valid JSON');
            return new JsonResponse(['error' => 'Invalid Message JSON'], Response::HTTP_BAD_REQUEST);
        }

        $notificationType = $message['notificationType'] ?? '';

        match ($notificationType) {
            'Bounce'    => $this->dispatchBounce($message),
            'Complaint' => $this->dispatchComplaint($message),
            default     => $this->logger->info('Unknown SES notification type', ['type' => $notificationType]),
        };

        return new JsonResponse(['status' => 'ok']);
    }

    private function dispatchBounce(array $message): void
    {
        $bounce    = $message['bounce'] ?? [];
        $bounceType = BounceType::tryFrom($bounce['bounceType'] ?? '') ?? BounceType::Undetermined;
        $timestamp  = $this->parseTimestamp($bounce['timestamp'] ?? '');

        foreach ($bounce['bouncedRecipients'] ?? [] as $recipientData) {
            $email = $recipientData['emailAddress'] ?? '';
            if ($email === '') {
                continue;
            }

            try {
                $notification = new BounceNotification(
                    recipient:      new EmailAddress($email),
                    bounceType:     $bounceType,
                    bounceSubType:  $bounce['bounceSubType'] ?? '',
                    diagnosticCode: $recipientData['diagnosticCode'] ?? '',
                    timestamp:      $timestamp,
                    feedbackId:     $bounce['feedbackId'] ?? null,
                );
                $this->bounceRunner->run($notification);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to dispatch bounce notification', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function dispatchComplaint(array $message): void
    {
        $complaint = $message['complaint'] ?? [];
        $timestamp = $this->parseTimestamp($complaint['timestamp'] ?? '');

        foreach ($complaint['complainedRecipients'] ?? [] as $recipientData) {
            $email = $recipientData['emailAddress'] ?? '';
            if ($email === '') {
                continue;
            }

            try {
                $notification = new ComplaintNotification(
                    recipient:             new EmailAddress($email),
                    complaintFeedbackType: $complaint['complaintFeedbackType'] ?? null,
                    timestamp:             $timestamp,
                    feedbackId:            $complaint['feedbackId'] ?? null,
                    userAgent:             $complaint['userAgent'] ?? null,
                );
                $this->complaintRunner->run($notification);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to dispatch complaint notification', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function parseTimestamp(string $timestamp): \DateTimeImmutable
    {
        if ($timestamp === '') {
            return new \DateTimeImmutable();
        }

        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);

        return $dt !== false ? $dt : new \DateTimeImmutable();
    }
}
