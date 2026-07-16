<?php

declare(strict_types=1);

namespace Vortos\Audit;

use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditSource;
use Vortos\Audit\Event\AuditTarget;
use Vortos\Audit\Enum\Outcome;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;

/**
 * The one API application code calls to write to the audit trail.
 *
 * It hides event assembly, vocabulary validation, and sensitivity resolution behind a
 * single method, then forwards the finished event to the configured recorder. Callers
 * never construct an AuditEvent or touch the storage seam directly.
 */
interface AuditTrailInterface
{
    /**
     * @param array<string, mixed>    $context     structured detail (the old "payload"); no secrets
     * @param ?Sensitivity            $sensitivity override; null resolves from the action vocabulary
     * @param ?\DateTimeImmutable     $occurredAt  the true time the audited business event happened.
     *                                             Pass the domain event's own timestamp on the async
     *                                             handler path so the stored `occurred_at` reflects
     *                                             reality regardless of consumer lag; leave null for
     *                                             inline/synchronous recorders, where "now" is the
     *                                             true time of the decision being recorded.
     */
    public function record(
        Scope              $scope,
        ?string            $tenantId,
        AuditActor         $actor,
        string             $action,
        ?AuditTarget       $target = null,
        Outcome            $outcome = Outcome::Allowed,
        ?AuditSource       $source = null,
        array              $context = [],
        ?Sensitivity       $sensitivity = null,
        ?\DateTimeImmutable $occurredAt = null,
    ): void;
}
