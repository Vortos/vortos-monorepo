<?php

declare(strict_types=1);

namespace Vortos\Audit\Integrity;

use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Storage\StoredAuditEvent;

/**
 * Tamper-evidence primitive for the audit spine.
 *
 * Two layers, mirroring the framework's auth/scheduler ledgers:
 *   - content_hash = SHA-256(canonicalJson(event) || prev_hash) links each record to
 *     its predecessor, so altering any past record breaks every hash after it.
 *   - signature = HMAC-SHA256(id:sequence:content_hash:prev_hash, key) with an off-host
 *     key means even an attacker with full DB write access cannot forge or re-chain
 *     records — the protection DB triggers alone cannot give.
 *
 * Canonical JSON (recursively key-sorted) makes the hash independent of map ordering.
 */
final class AuditHashChain
{
    /** SHA-256 of the empty string — the prev_hash of the first record in a chain. */
    public const GENESIS_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function contentHash(AuditEvent $event, string $prevHash): string
    {
        return hash('sha256', $this->canonicalJson($event->toArray()) . $prevHash);
    }

    public function signingMessage(string $id, int $sequence, string $contentHash, string $prevHash): string
    {
        return implode(':', [$id, (string) $sequence, $contentHash, $prevHash]);
    }

    public function sign(string $message, string $hmacKey): string
    {
        if ($hmacKey === '') {
            throw new \InvalidArgumentException('Audit HMAC key must not be empty when signing.');
        }

        return hash_hmac('sha256', $message, $hmacKey);
    }

    public function verifySignature(string $message, string $signature, string $hmacKey): bool
    {
        if ($hmacKey === '') {
            // Unsigned chain: nothing to verify against, treat only content_hash as authority.
            return $signature === '';
        }

        return hash_equals($this->sign($message, $hmacKey), $signature);
    }

    /**
     * Produce the stored, chained form of an event. An empty HMAC key yields an unsigned
     * record (content-hash chain only) — permitted in tests, discouraged in production.
     */
    public function chain(AuditEvent $event, string $chainKey, int $sequence, string $prevHash, string $hmacKey): StoredAuditEvent
    {
        $contentHash = $this->contentHash($event, $prevHash);
        $signature   = $hmacKey === ''
            ? ''
            : $this->sign($this->signingMessage($event->id, $sequence, $contentHash, $prevHash), $hmacKey);

        return new StoredAuditEvent($event, $chainKey, $sequence, $prevHash, $contentHash, $signature);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->sortRecursively($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $sorted = [];
        foreach ($value as $k => $v) {
            $sorted[$k] = $this->sortRecursively($v);
        }
        if (!$isList) {
            ksort($sorted);
        }

        return $sorted;
    }
}
