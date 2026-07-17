<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

use Vortos\Audit\Enum\Outcome;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;
use Vortos\Audit\Query\AuditQuery;

/**
 * The query-shaping half of an export request — everything an {@see AuditQuery} carries
 * except scope/tenant (which are job columns) and pagination (which the streaming exporter
 * drives). Persisted as JSON on the job so the consumer rebuilds the exact same filter the
 * requester saw in the console, hours later, on a different process.
 */
final readonly class AuditExportFilter
{
    public function __construct(
        public ?string             $actorId = null,
        public ?string             $action = null,
        public ?string             $actionPrefix = null,
        public ?Sensitivity        $minSensitivity = null,
        public ?Outcome            $outcome = null,
        public ?string             $targetType = null,
        public ?string             $targetId = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?string             $search = null,
    ) {}

    /** Rebuild the full query for a given scope/tenant. Pagination is left at defaults; the
     *  streaming exporter overrides cursor/limit per page. */
    public function toAuditQuery(Scope $scope, ?string $tenantId): AuditQuery
    {
        return new AuditQuery(
            scope:          $scope,
            tenantId:       $tenantId,
            actorId:        $this->actorId,
            action:         $this->action,
            minSensitivity: $this->minSensitivity,
            outcome:        $this->outcome,
            targetType:     $this->targetType,
            targetId:       $this->targetId,
            from:           $this->from,
            to:             $this->to,
            actionPrefix:   $this->actionPrefix,
            search:         $this->search,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_id'        => $this->actorId,
            'action'          => $this->action,
            'action_prefix'   => $this->actionPrefix,
            'min_sensitivity' => $this->minSensitivity?->value,
            'outcome'         => $this->outcome?->value,
            'target_type'     => $this->targetType,
            'target_id'       => $this->targetId,
            'from'            => $this->from?->format('Y-m-d\TH:i:s.uP'),
            'to'              => $this->to?->format('Y-m-d\TH:i:s.uP'),
            'search'          => $this->search,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            actorId:        self::str($data['actor_id'] ?? null),
            action:         self::str($data['action'] ?? null),
            actionPrefix:   self::str($data['action_prefix'] ?? null),
            minSensitivity: isset($data['min_sensitivity']) ? Sensitivity::tryFrom((string) $data['min_sensitivity']) : null,
            outcome:        isset($data['outcome']) ? Outcome::tryFrom((string) $data['outcome']) : null,
            targetType:     self::str($data['target_type'] ?? null),
            targetId:       self::str($data['target_id'] ?? null),
            from:           self::date($data['from'] ?? null),
            to:             self::date($data['to'] ?? null),
            search:         self::str($data['search'] ?? null),
        );
    }

    private static function str(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : (string) $v;
    }

    private static function date(mixed $v): ?\DateTimeImmutable
    {
        return $v === null || $v === '' ? null : new \DateTimeImmutable((string) $v);
    }
}
