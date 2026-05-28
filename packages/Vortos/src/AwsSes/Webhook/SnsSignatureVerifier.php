<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Webhook;

use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Exception\WebhookVerificationException;

/**
 * Verifies AWS SNS message signatures.
 *
 * SNS signs Notification and SubscriptionConfirmation messages using an RSA key.
 * We validate the signing cert URL, fetch the cert, build the canonical string,
 * and verify the provided Base64 signature.
 *
 * SignatureVersion 1 = SHA1withRSA; SignatureVersion 2 = SHA256withRSA.
 */
final class SnsSignatureVerifier implements SignatureVerifierInterface
{
    /** @param \Closure(string):string $certFetcher fn(string $url): PEM string */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly \Closure $certFetcher,
    ) {}

    /** @throws WebhookVerificationException */
    public function verify(array $payload): void
    {
        $certUrl = $payload['SigningCertURL'] ?? '';
        $this->assertTrustedCertUrl($certUrl);

        $stringToSign = $this->buildStringToSign($payload);
        $signature    = base64_decode($payload['Signature'] ?? '', strict: true);

        if ($signature === false) {
            throw WebhookVerificationException::invalidSignature();
        }

        $pem    = ($this->certFetcher)($certUrl);
        $pubKey = openssl_get_publickey($pem);

        if ($pubKey === false) {
            throw WebhookVerificationException::invalidSignature();
        }

        $algo   = $this->resolveAlgo($payload['SignatureVersion'] ?? '1');
        $result = openssl_verify($stringToSign, $signature, $pubKey, $algo);

        if ($result !== 1) {
            $this->logger->warning('SNS signature verification failed', [
                'cert_url'  => $certUrl,
                'openssl_error' => openssl_error_string(),
            ]);
            throw WebhookVerificationException::invalidSignature();
        }
    }

    private function assertTrustedCertUrl(string $url): void
    {
        if ($url === '') {
            throw WebhookVerificationException::missingHeader('SigningCertURL');
        }

        $parsed = parse_url($url);

        if (($parsed['scheme'] ?? '') !== 'https') {
            throw WebhookVerificationException::untrustedCertUrl($url);
        }

        $host = $parsed['host'] ?? '';

        // Must end in .amazonaws.com — covers sns.{region}.amazonaws.com
        // and FIPS variants like sns-fips.{region}.amazonaws.com
        if (!preg_match('/\.amazonaws\.com$/', $host)) {
            throw WebhookVerificationException::untrustedCertUrl($url);
        }
    }

    private function buildStringToSign(array $payload): string
    {
        $type   = $payload['Type'] ?? '';
        $fields = match ($type) {
            'Notification'             => $this->notificationFields($payload),
            'SubscriptionConfirmation',
            'UnsubscribeConfirmation'  => $this->subscriptionFields($payload),
            default                    => [],
        };

        $parts = [];
        foreach ($fields as $key) {
            if (isset($payload[$key])) {
                $parts[] = $key;
                $parts[] = $payload[$key];
            }
        }

        return implode("\n", $parts) . "\n";
    }

    /** @return string[] */
    private function notificationFields(array $payload): array
    {
        $fields = ['Message', 'MessageId'];
        if (isset($payload['Subject'])) {
            $fields[] = 'Subject';
        }
        $fields[] = 'Timestamp';
        $fields[] = 'TopicArn';
        $fields[] = 'Type';

        return $fields;
    }

    /** @return string[] */
    private function subscriptionFields(array $payload): array
    {
        return ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
    }

    private function resolveAlgo(string $version): int
    {
        return match ($version) {
            '2'     => OPENSSL_ALGO_SHA256,
            default => OPENSSL_ALGO_SHA1,
        };
    }

    /**
     * Cert fetcher that caches PEM files via PSR-16 CacheInterface.
     *
     * Avoids an external HTTPS round-trip to amazonaws.com on every webhook —
     * critical under bounce storms where hundreds of requests may arrive in seconds.
     * TTL: 24 hours (AWS SNS certs rotate infrequently).
     */
    public static function cachedCertFetcher(\Psr\SimpleCache\CacheInterface $cache, int $ttlSeconds = 86400): \Closure
    {
        return static function (string $url) use ($cache, $ttlSeconds): string {
            $cacheKey = 'ses_sns_cert_' . md5($url);

            $cached = $cache->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $pem = @file_get_contents($url);
            if ($pem === false || $pem === '') {
                throw new \RuntimeException(sprintf('Failed to fetch SNS signing cert from "%s"', $url));
            }

            $cache->set($cacheKey, $pem, $ttlSeconds);

            return $pem;
        };
    }

    /** Uncached cert fetcher — use only where no cache is available (e.g. tests). */
    public static function defaultCertFetcher(): \Closure
    {
        return static function (string $url): string {
            $pem = @file_get_contents($url);
            if ($pem === false) {
                throw new \RuntimeException(sprintf('Failed to fetch SNS signing cert from "%s"', $url));
            }
            return $pem;
        };
    }
}
