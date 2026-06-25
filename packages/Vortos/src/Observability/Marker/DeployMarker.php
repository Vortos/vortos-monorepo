<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker;

/**
 * A deploy/rollback annotation rendered to the metrics/tracing backend (Block 16,
 * §3.2) — links a dashboard regression to "which deploy/SHA caused it" in one click.
 * Carries no secrets/PII: ids + git SHA + manifest link only.
 */
final readonly class DeployMarker
{
    /**
     * @param list<string> $tags
     * @param array<string, string> $links
     */
    public function __construct(
        public string $env,
        public string $kind,
        public string $buildId,
        public string $gitSha,
        public string $imageDigest,
        public string $schemaFingerprintId,
        public string $title,
        public array $tags,
        public \DateTimeImmutable $at,
        public array $links = [],
    ) {
        if (!in_array($kind, ['deploy', 'rollback'], true)) {
            throw new \InvalidArgumentException(sprintf('DeployMarker kind must be "deploy" or "rollback", got "%s".', $kind));
        }
    }

    /** Stable idempotency key so a retried deploy never double-annotates. */
    public function idempotencyKey(): string
    {
        return hash('sha256', $this->env . '|' . $this->kind . '|' . $this->buildId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'env' => $this->env,
            'kind' => $this->kind,
            'build_id' => $this->buildId,
            'git_sha' => $this->gitSha,
            'image_digest' => $this->imageDigest,
            'schema_fingerprint_id' => $this->schemaFingerprintId,
            'title' => $this->title,
            'tags' => $this->tags,
            'at' => $this->at->format(\DateTimeInterface::ATOM),
            'links' => $this->links,
            'idempotency_key' => $this->idempotencyKey(),
        ];
    }
}
