<?php

declare(strict_types=1);

namespace Vortos\Push\Driver;

use Vortos\Push\Config\VapidKeys;
use Vortos\Push\Contract\WebPushDeliveryStatus;
use Vortos\Push\Contract\WebPushMessage;
use Vortos\Push\Contract\WebPushResult;
use Vortos\Push\Contract\WebPushSenderInterface;
use Vortos\Push\Contract\WebPushSubscription;
use Vortos\Push\Crypto\WebPushEncryptor;
use Vortos\Push\Vapid\VapidHeaderFactory;

/**
 * The default Web Push sender: encrypts the payload (RFC 8291 aes128gcm), attaches
 * the VAPID authorization, and POSTs it to the subscription endpoint over curl.
 * Classifies the response so callers can revoke dead subscriptions (Gone) and
 * retry transient failures (Failed).
 */
final class CurlWebPushSender implements WebPushSenderInterface
{
    public function __construct(
        private readonly WebPushEncryptor $encryptor,
        private readonly VapidHeaderFactory $vapid,
        private readonly VapidKeys $keys,
    ) {}

    public function isConfigured(): bool
    {
        return $this->keys->isConfigured();
    }

    public function publicKey(): ?string
    {
        return $this->keys->isConfigured() ? $this->keys->publicKey() : null;
    }

    public function send(WebPushSubscription $subscription, WebPushMessage $message): WebPushResult
    {
        if (!$this->keys->isConfigured()) {
            throw new \RuntimeException('VAPID keys are not configured.');
        }

        $body = $this->encryptor->encrypt($message->payload, $subscription->p256dh, $subscription->auth);

        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . $message->ttl,
            'Urgency: ' . $message->urgency,
            'Content-Length: ' . strlen($body),
            'Authorization: ' . $this->vapid->authorizationHeader($subscription->endpoint),
        ];
        if ($message->topic !== null) {
            $headers[] = 'Topic: ' . $message->topic;
        }

        $ch = curl_init($subscription->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status === 0) {
            return new WebPushResult(WebPushDeliveryStatus::Failed, 0, $error !== '' ? $error : 'transport error');
        }

        $classified = match (true) {
            $status >= 200 && $status < 300     => WebPushDeliveryStatus::Delivered,
            $status === 404 || $status === 410  => WebPushDeliveryStatus::Gone,
            default                             => WebPushDeliveryStatus::Failed,
        };

        return new WebPushResult($classified, $status, $classified === WebPushDeliveryStatus::Delivered ? null : ('HTTP ' . $status));
    }
}
