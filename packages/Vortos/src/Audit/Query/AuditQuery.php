<?php

declare(strict_types=1);

namespace Vortos\Audit\Query;

use Vortos\Audit\Enum\Outcome;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;

/**
 * Filter + pagination spec for reading the trail. Always scope-bound: a tenant query
 * carries its tenantId so an org admin can only ever see their own trail; a platform
 * query spans tenants. `minSensitivity` powers the "high only / what admins did" view.
 */
final readonly class AuditQuery
{
    public function __construct(
        public Scope               $scope,
        public ?string             $tenantId = null,
        public ?string             $actorId = null,
        public ?string             $action = null,
        public ?Sensitivity        $minSensitivity = null,
        public ?Outcome            $outcome = null,
        public ?string             $targetType = null,
        public ?string             $targetId = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?AuditCursor        $cursor = null,
        public int                 $limit = 50,
    ) {
        if ($scope->requiresTenantId() && ($tenantId === null || $tenantId === '')) {
            throw new \InvalidArgumentException('A tenant-scoped audit query requires a tenantId.');
        }
    }

    public function boundedLimit(int $max = 200): int
    {
        return max(1, min($max, $this->limit));
    }

    public function withCursor(?AuditCursor $cursor): self
    {
        return new self(
            $this->scope, $this->tenantId, $this->actorId, $this->action, $this->minSensitivity,
            $this->outcome, $this->targetType, $this->targetId, $this->from, $this->to, $cursor, $this->limit,
        );
    }

    public function withLimit(int $limit): self
    {
        return new self(
            $this->scope, $this->tenantId, $this->actorId, $this->action, $this->minSensitivity,
            $this->outcome, $this->targetType, $this->targetId, $this->from, $this->to, $this->cursor, $limit,
        );
    }
}
