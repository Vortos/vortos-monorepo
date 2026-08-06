<?php

declare(strict_types=1);

namespace Vortos\Paddle\Gateway;

use Vortos\Paddle\Exception\WebhookReplayException;
use Vortos\Paddle\Exception\WebhookSignatureException;
use Vortos\Paddle\Webhook\WebhookVerifierInterface;
use Vortos\Payments\Contract\SignatureVerifierInterface;
use Vortos\Payments\Exception\SignatureVerificationException;
use Vortos\Payments\Webhook\SignedPayload;

/**
 * Paddle's webhook signature, behind the rail-agnostic contract.
 *
 * The verification itself is unchanged — it stays in {@see \Vortos\Paddle\Webhook\WebhookVerifier},
 * which is HMAC-SHA256 over `{timestamp}:{rawBody}` with a replay window, and
 * is already live. This only restates the outcome in the vocabulary a
 * rail-agnostic webhook endpoint can handle, so one endpoint shape works for
 * every rail regardless of whether it signs a header or a form body.
 *
 * The raw body is passed through untouched. A body that has been JSON-decoded
 * and re-encoded is not the signed body however identical it looks — key
 * order and escaping both move — and that failure presents as a wrong secret,
 * which is a long afternoon.
 */
final class PaddleSignatureVerifier implements SignatureVerifierInterface
{
    public const SIGNATURE_HEADER = 'paddle-signature';

    public function __construct(
        private readonly WebhookVerifierInterface $verifier,
    ) {}

    public function verify(SignedPayload $payload): void
    {
        $signature = $payload->header(self::SIGNATURE_HEADER) ?? '';

        // Absent is not a special case of invalid: a bare POST from someone who
        // found the URL should be rejected before any parsing decides it is
        // worth looking at.
        if (trim($signature) === '') {
            throw SignatureVerificationException::missing(PaddleGateway::ID);
        }

        try {
            $this->verifier->verify($payload->rawBody, $signature);
        } catch (WebhookReplayException $e) {
            throw SignatureVerificationException::mismatch(PaddleGateway::ID);
        } catch (WebhookSignatureException $e) {
            // Deliberately not forwarding the underlying message. Paddle's
            // wording distinguishes a malformed header from a wrong HMAC, and
            // an endpoint that reports which is an oracle for anyone probing it.
            throw SignatureVerificationException::mismatch(PaddleGateway::ID);
        }
    }
}
