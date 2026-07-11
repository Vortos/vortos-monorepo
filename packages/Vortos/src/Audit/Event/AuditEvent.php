<?php

declare(strict_types=1);

namespace Vortos\Audit\Event;

use Symfony\Component\Uid\UuidV7;
use Vortos\Audit\Enum\Outcome;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;

/**
 * The single canonical audit record for the whole platform.
 *
 * One immutable value object replaces the three hand-rolled shapes (auth / platform /
 * org). It is append-only: it carries no chain fields itself — sequence/prev_hash/
 * row_hash are assigned by the store when the event is committed to its scope chain
 * (P2), keeping the domain event free of storage concerns.
 *
 * `context` is a small structured detail blob (the old "payload"); it must stay
 * JSON-serialisable and should never carry secrets or full entity dumps.
 */
final readonly class AuditEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string             $id,
        public Scope              $scope,
        public ?string            $tenantId,
        public AuditActor         $actor,
        public string             $action,
        public ?AuditTarget       $target,
        public Sensitivity        $sensitivity,
        public Outcome            $outcome,
        public AuditSource        $source,
        public array              $context,
        public \DateTimeImmutable $occurredAt,
    ) {
        if ($scope->requiresTenantId() && ($tenantId === null || $tenantId === '')) {
            throw new \InvalidArgumentException('Tenant-scoped audit events require a non-empty tenantId.');
        }
        if (!$scope->requiresTenantId() && $tenantId !== null) {
            throw new \InvalidArgumentException('Platform-scoped audit events must not carry a tenantId.');
        }
        if ($action === '') {
            throw new \InvalidArgumentException('Audit action must not be empty.');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function create(
        Scope              $scope,
        ?string            $tenantId,
        AuditActor         $actor,
        string             $action,
        ?AuditTarget       $target = null,
        Sensitivity        $sensitivity = Sensitivity::Normal,
        Outcome            $outcome = Outcome::Allowed,
        ?AuditSource       $source = null,
        array              $context = [],
        ?\DateTimeImmutable $occurredAt = null,
    ): self {
        return new self(
            id:          (string) new UuidV7(),
            scope:       $scope,
            tenantId:    $tenantId,
            actor:       $actor,
            action:      $action,
            target:      $target,
            sensitivity: $sensitivity,
            outcome:     $outcome,
            source:      $source ?? AuditSource::empty(),
            context:     $context,
            occurredAt:  $occurredAt ?? new \DateTimeImmutable(),
        );
    }

    /**
     * The chain-partition key: which hash chain this event belongs to.
     * Platform events share one chain; tenant events get one chain each.
     */
    public function chainKey(): string
    {
        return $this->scope === Scope::Platform
            ? 'platform'
            : 'tenant:' . $this->tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'scope'       => $this->scope->value,
            'tenant_id'   => $this->tenantId,
            'actor'       => $this->actor->toArray(),
            'action'      => $this->action,
            'target'      => $this->target?->toArray(),
            'sensitivity' => $this->sensitivity->value,
            'outcome'     => $this->outcome->value,
            'source'      => $this->source->toArray(),
            'context'     => $this->context,
            // Microsecond precision (not RFC3339_EXTENDED's milliseconds): the hash chain
            // orders by occurred_at, so truncating to ms would collide sub-ms events.
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:          (string) $data['id'],
            scope:       Scope::from((string) $data['scope']),
            tenantId:    isset($data['tenant_id']) ? (string) $data['tenant_id'] : null,
            actor:       AuditActor::fromArray((array) $data['actor']),
            action:      (string) $data['action'],
            target:      isset($data['target']) && is_array($data['target'])
                ? AuditTarget::fromArray($data['target'])
                : null,
            sensitivity: Sensitivity::from((string) $data['sensitivity']),
            outcome:     Outcome::from((string) $data['outcome']),
            source:      AuditSource::fromArray((array) ($data['source'] ?? [])),
            context:     (array) ($data['context'] ?? []),
            occurredAt:  new \DateTimeImmutable((string) $data['occurred_at']),
        );
    }
}
