<?php

declare(strict_types=1);

namespace Vortos\Paddle\Webhook;

use Vortos\Paddle\Exception\WebhookReplayException;
use Vortos\Paddle\Exception\WebhookSignatureException;

final class WebhookVerifier implements WebhookVerifierInterface
{
    /** @param string|string[] $notificationSecrets */
    public function __construct(
        #[\SensitiveParameter] private readonly string|array $notificationSecrets,
        private readonly int $replayWindowSeconds = 5,
    ) {}

    public function verify(string $rawBody, string $signatureHeader): void
    {
        if ($signatureHeader === '') {
            throw WebhookSignatureException::missingHeader();
        }

        $parsed = $this->parseHeader($signatureHeader);
        $ts     = $parsed['ts'] ?? '';
        $h1     = $parsed['h1'] ?? '';

        if ($ts === '' || $h1 === '') {
            throw WebhookSignatureException::malformedHeader($signatureHeader);
        }

        $this->enforceReplayWindow((int) $ts);

        $secrets = is_array($this->notificationSecrets)
            ? $this->notificationSecrets
            : [$this->notificationSecrets];

        $payload = $ts . ':' . $rawBody;

        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $payload, $secret);
            if (hash_equals($expected, $h1)) {
                return;
            }
        }

        if (count($secrets) === 1) {
            throw WebhookSignatureException::invalidHmac();
        }

        throw WebhookSignatureException::noValidSecret();
    }

    private function parseHeader(string $header): array
    {
        $result = [];
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $key          = substr($part, 0, $eq);
            $value        = substr($part, $eq + 1);
            $result[$key] = $value;
        }
        return $result;
    }

    private function enforceReplayWindow(int $ts): void
    {
        $now = time();
        $age = abs($now - $ts);

        if ($age > $this->replayWindowSeconds) {
            throw WebhookReplayException::timestampExpired($age, $this->replayWindowSeconds);
        }
    }
}
