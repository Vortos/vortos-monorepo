<?php

declare(strict_types=1);

namespace Vortos\Auth\Audit\Integrity;

use Vortos\Auth\Audit\AuditEntry;

final class AuthAuditHashChain
{
    public const GENESIS_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function contentHash(array $hashableFields, string $prevHash): string
    {
        return hash('sha256', $this->canonicalJson($hashableFields) . $prevHash);
    }

    public function signingMessage(string $entryId, int $sequence, string $contentHash, string $prevHash): string
    {
        return implode(':', [$entryId, (string) $sequence, $contentHash, $prevHash]);
    }

    public function sign(string $signingMessage, string $hmacKey): string
    {
        if ($hmacKey === '') {
            throw new \InvalidArgumentException('Auth audit HMAC key must not be empty.');
        }

        return hash_hmac('sha256', $signingMessage, $hmacKey);
    }

    public function verifySignature(string $signingMessage, string $signature, string $hmacKey): bool
    {
        if ($hmacKey === '') {
            return false;
        }

        return hash_equals($this->sign($signingMessage, $hmacKey), $signature);
    }

    public function chain(AuditEntry $entry, int $sequence, string $prevHash, string $hmacKey): AuditEntry
    {
        $hashable = [
            'id' => $entry->id,
            'user_id' => $entry->userId,
            'action' => $entry->action,
            'resource_id' => $entry->resourceId,
            'ip_address' => $entry->ipAddress,
            'user_agent' => $entry->userAgent,
            'occurred_at' => $entry->occurredAt->format(\DateTimeInterface::ATOM),
            'metadata' => $entry->metadata,
        ];

        $contentHash = $this->contentHash($hashable, $prevHash);
        $signingMsg = $this->signingMessage($entry->id, $sequence, $contentHash, $prevHash);
        $signature = $this->sign($signingMsg, $hmacKey);

        return $entry->withIntegrity($sequence, $prevHash, $contentHash, $signature);
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->sortRecursively($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (is_array($value)) {
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

        return $value;
    }
}
