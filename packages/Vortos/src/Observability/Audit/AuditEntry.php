<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

/**
 * One append-only, hash-chained, HMAC-signed row in the deploy audit ledger
 * (Block 16, §3.1). `sequence` is monotonic per `env` (the chain key); `prevHash`
 * links to the previous entry's `contentHash` for the same env — genesis entries
 * use {@see AuditHashChain::GENESIS_HASH}.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, mixed> $data Scrubbed event-specific payload (no secrets/PII)
     */
    public function __construct(
        public string $entryId,
        public int $sequence,
        public string $eventType,
        public string $actorId,
        public string $actorIdentitySource,
        public string $env,
        public string $buildId,
        public string $gitSha,
        public string $imageDigest,
        public string $schemaFingerprintId,
        public ?string $reason,
        public string $occurredAt,
        public array $data,
        public string $prevHash,
        public string $contentHash,
        public string $signature,
    ) {
        if ($sequence < 0) {
            throw new \InvalidArgumentException('Audit entry sequence must be >= 0.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entry_id' => $this->entryId,
            'sequence' => $this->sequence,
            'event_type' => $this->eventType,
            'actor_id' => $this->actorId,
            'actor_identity_source' => $this->actorIdentitySource,
            'env' => $this->env,
            'build_id' => $this->buildId,
            'git_sha' => $this->gitSha,
            'image_digest' => $this->imageDigest,
            'schema_fingerprint_id' => $this->schemaFingerprintId,
            'reason' => $this->reason,
            'occurred_at' => $this->occurredAt,
            'data' => $this->data,
            'prev_hash' => $this->prevHash,
            'content_hash' => $this->contentHash,
            'signature' => $this->signature,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            entryId: (string) $data['entry_id'],
            sequence: (int) $data['sequence'],
            eventType: (string) $data['event_type'],
            actorId: (string) $data['actor_id'],
            actorIdentitySource: (string) $data['actor_identity_source'],
            env: (string) $data['env'],
            buildId: (string) $data['build_id'],
            gitSha: (string) $data['git_sha'],
            imageDigest: (string) $data['image_digest'],
            schemaFingerprintId: (string) $data['schema_fingerprint_id'],
            reason: $data['reason'] !== null ? (string) $data['reason'] : null,
            occurredAt: (string) $data['occurred_at'],
            data: (array) $data['data'],
            prevHash: (string) $data['prev_hash'],
            contentHash: (string) $data['content_hash'],
            signature: (string) $data['signature'],
        );
    }

    /**
     * The fields covered by the content hash — everything except the chain/signature
     * fields themselves (those are *derived from* this canonical payload).
     *
     * @return array<string, mixed>
     */
    public function hashableFields(): array
    {
        $fields = $this->toArray();
        unset($fields['prev_hash'], $fields['content_hash'], $fields['signature']);

        return $fields;
    }
}
