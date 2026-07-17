<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

use Vortos\Audit\Enum\AuditExportStatus;
use Vortos\Audit\Enum\Scope;

/**
 * A tracked async export request and its lifecycle. Created `Queued` when a user asks to
 * export their trail; advanced by the export consumer to `Running`, then `Ready` (with the
 * object keys + attestable facts) or `Failed`; and eventually `Expired` when the artifact is
 * garbage-collected. Transitions are guarded — an illegal move throws — so the state machine
 * can never be corrupted by a redelivery or a race.
 */
final class AuditExportJob
{
    private function __construct(
        public readonly string             $id,
        public readonly Scope              $scope,
        public readonly ?string            $tenantId,
        public readonly string             $requestedByActorId,
        public readonly ?string            $requestedByLabel,
        public readonly AuditExportFilter  $filter,
        private AuditExportStatus          $status,
        private ?int                       $recordCount,
        private ?int                       $byteSize,
        private ?string                    $contentSha256,
        private ?string                    $bodyKey,
        private ?string                    $manifestKey,
        private ?string                    $error,
        public readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable         $updatedAt,
        private ?\DateTimeImmutable        $expiresAt,
    ) {}

    public static function queue(
        string             $id,
        Scope              $scope,
        ?string            $tenantId,
        string             $requestedByActorId,
        ?string            $requestedByLabel,
        AuditExportFilter  $filter,
        \DateTimeImmutable $now,
    ): self {
        if ($scope->requiresTenantId() && ($tenantId === null || $tenantId === '')) {
            throw new \InvalidArgumentException('A tenant-scoped export job requires a tenantId.');
        }

        return new self(
            id:                 $id,
            scope:              $scope,
            tenantId:           $tenantId,
            requestedByActorId: $requestedByActorId,
            requestedByLabel:   $requestedByLabel,
            filter:             $filter,
            status:             AuditExportStatus::Queued,
            recordCount:        null,
            byteSize:           null,
            contentSha256:      null,
            bodyKey:            null,
            manifestKey:        null,
            error:              null,
            createdAt:          $now,
            updatedAt:          $now,
            expiresAt:          null,
        );
    }

    public function markRunning(\DateTimeImmutable $now): void
    {
        // Idempotent: a redelivery that re-runs an already-running job is a no-op, not a fault.
        if ($this->status === AuditExportStatus::Running) {
            return;
        }
        $this->guard(AuditExportStatus::Queued, 'start');
        $this->status    = AuditExportStatus::Running;
        $this->updatedAt = $now;
    }

    public function markReady(AuditExportResult $result, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        if ($this->status === AuditExportStatus::Ready) {
            return; // already recorded by an earlier delivery
        }
        $this->guard(AuditExportStatus::Running, 'complete');
        $this->status        = AuditExportStatus::Ready;
        $this->recordCount   = $result->recordCount;
        $this->byteSize      = $result->byteSize;
        $this->contentSha256 = $result->contentSha256;
        $this->bodyKey       = $result->bodyKey;
        $this->manifestKey   = $result->manifestKey;
        $this->error         = null;
        $this->updatedAt     = $now;
        $this->expiresAt     = $expiresAt;
    }

    public function markFailed(string $error, \DateTimeImmutable $now): void
    {
        if ($this->status->isTerminal()) {
            return;
        }
        $this->status    = AuditExportStatus::Failed;
        $this->error     = mb_substr($error, 0, 1000);
        $this->updatedAt = $now;
    }

    public function markExpired(\DateTimeImmutable $now): void
    {
        if ($this->status === AuditExportStatus::Expired) {
            return;
        }
        $this->guard(AuditExportStatus::Ready, 'expire');
        $this->status    = AuditExportStatus::Expired;
        $this->updatedAt = $now;
    }

    private function guard(AuditExportStatus $expected, string $action): void
    {
        if ($this->status !== $expected) {
            throw new \LogicException(sprintf(
                'Cannot %s audit export job %s from status "%s" (expected "%s").',
                $action,
                $this->id,
                $this->status->value,
                $expected->value,
            ));
        }
    }

    // ── Accessors ────────────────────────────────────────────────────────────────

    public function status(): AuditExportStatus { return $this->status; }
    public function recordCount(): ?int { return $this->recordCount; }
    public function byteSize(): ?int { return $this->byteSize; }
    public function contentSha256(): ?string { return $this->contentSha256; }
    public function bodyKey(): ?string { return $this->bodyKey; }
    public function manifestKey(): ?string { return $this->manifestKey; }
    public function error(): ?string { return $this->error; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function expiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }

    /** True when the artifact's retention window has passed and it should be GC'd. */
    public function isPastRetention(\DateTimeImmutable $now): bool
    {
        return $this->status === AuditExportStatus::Ready
            && $this->expiresAt !== null
            && $this->expiresAt <= $now;
    }

    /**
     * Rehydrate from a persisted row. Package-internal — the store owns the wire shape.
     */
    public static function rehydrate(
        string             $id,
        Scope              $scope,
        ?string            $tenantId,
        string             $requestedByActorId,
        ?string            $requestedByLabel,
        AuditExportFilter  $filter,
        AuditExportStatus  $status,
        ?int               $recordCount,
        ?int               $byteSize,
        ?string            $contentSha256,
        ?string            $bodyKey,
        ?string            $manifestKey,
        ?string            $error,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            $id, $scope, $tenantId, $requestedByActorId, $requestedByLabel, $filter, $status,
            $recordCount, $byteSize, $contentSha256, $bodyKey, $manifestKey, $error,
            $createdAt, $updatedAt, $expiresAt,
        );
    }
}
