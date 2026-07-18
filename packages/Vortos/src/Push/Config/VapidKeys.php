<?php

declare(strict_types=1);

namespace Vortos\Push\Config;

/**
 * VAPID application-server keys (RFC 8292), injected from the environment:
 *   VAPID_PUBLIC_KEY   base64url uncompressed P-256 public point (65 bytes)
 *   VAPID_PRIVATE_KEY  PEM-encoded EC private key (may use literal "\n")
 *   VAPID_SUBJECT      a mailto: or https: contact URL
 *
 * Generate a pair with `bin/console vortos:push:vapid:generate`.
 */
final class VapidKeys
{
    public function __construct(
        private readonly string $publicKey = '',
        private readonly string $privateKeyPem = '',
        private readonly string $subject = '',
    ) {}

    public function isConfigured(): bool
    {
        return $this->publicKey !== '' && $this->privateKeyPem !== '' && $this->subject !== '';
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /** PEM may arrive single-line with literal "\n"; normalise to real newlines. */
    public function privateKeyPem(): string
    {
        return str_replace('\n', "\n", $this->privateKeyPem);
    }

    public function subject(): string
    {
        return $this->subject;
    }
}
