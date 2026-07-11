<?php

declare(strict_types=1);

namespace Vortos\Audit\Query;

/**
 * Keyset pagination cursor over (occurred_at, id) — the pair is unique and indexed, so
 * paging stays O(log n) regardless of depth (unlike OFFSET, which the old audit list
 * used and which degrades on deep pages). Opaque base64 token on the wire.
 */
final readonly class AuditCursor
{
    public function __construct(
        public string $occurredAt,
        public string $id,
    ) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode($this->occurredAt . '|' . $this->id), '+/', '-_'), '=');
    }

    public static function decode(string $token): ?self
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || !str_contains($raw, '|')) {
            return null;
        }
        [$occurredAt, $id] = explode('|', $raw, 2);

        return new self($occurredAt, $id);
    }
}
