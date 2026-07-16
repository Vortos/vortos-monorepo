<?php

declare(strict_types=1);

namespace Vortos\Audit;

use Vortos\Audit\Action\AuditActionRegistry;
use Vortos\Audit\Contract\AuditRecorderInterface;
use Vortos\Audit\Enum\Outcome;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;
use Vortos\Audit\Event\AuditActor;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Audit\Event\AuditSource;
use Vortos\Audit\Event\AuditTarget;
use Vortos\Audit\Exception\UnknownAuditActionException;

/**
 * Default {@see AuditTrailInterface}: validate → resolve sensitivity → assemble → record.
 *
 * Vocabulary enforcement is the point. In strict mode (default) an undeclared action
 * throws, so typos and rogue strings never reach the permanent log. Sensitivity is
 * resolved with an explicit override winning over the action's declared default over
 * Normal — a High-sensitivity declaration can never be silently downgraded below itself.
 */
final class AuditTrail implements AuditTrailInterface
{
    public function __construct(
        private readonly AuditRecorderInterface $recorder,
        private readonly AuditActionRegistry    $registry,
        private readonly bool                   $strict = true,
    ) {}

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
    ): void {
        $declared = $this->registry->get($action);

        if ($declared === null && $this->strict) {
            throw UnknownAuditActionException::forKey($action);
        }

        $this->recorder->record(AuditEvent::create(
            scope:       $scope,
            tenantId:    $tenantId,
            actor:       $actor,
            action:      $action,
            target:      $target,
            sensitivity: $this->resolveSensitivity($sensitivity, $declared?->sensitivity),
            outcome:     $outcome,
            source:      $source,
            context:     $context,
            occurredAt:  $occurredAt,
        ));
    }

    /**
     * Explicit override wins, but never below the action's declared floor: an action
     * declared High stays at least High even if a caller passes Normal.
     */
    private function resolveSensitivity(?Sensitivity $override, ?Sensitivity $declared): Sensitivity
    {
        if ($override === null) {
            return $declared ?? Sensitivity::Normal;
        }
        if ($declared !== null && $declared->atLeast($override) && !$override->atLeast($declared)) {
            return $declared;
        }

        return $override;
    }
}
