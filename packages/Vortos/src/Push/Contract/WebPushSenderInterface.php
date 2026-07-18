<?php

declare(strict_types=1);

namespace Vortos\Push\Contract;

/**
 * Sends a single encrypted Web Push message to one subscription.
 *
 * The default driver (CurlWebPushSender) performs RFC 8291 aes128gcm encryption
 * and VAPID authorization. Applications depend on this interface — never the
 * driver — so an alternative transport (or an APNs/FCM sibling) can be swapped
 * in without touching calling code.
 */
interface WebPushSenderInterface
{
    public function send(WebPushSubscription $subscription, WebPushMessage $message): WebPushResult;

    /** Whether VAPID keys are configured; when false, callers should skip push. */
    public function isConfigured(): bool;

    /** The base64url VAPID public key to hand the browser as applicationServerKey. */
    public function publicKey(): ?string;
}
