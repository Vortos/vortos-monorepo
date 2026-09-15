<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

final readonly class DatabaseRoleViolation
{
    public function __construct(
        public DatabaseRoleViolationKind $kind,
        /** The role the finding is about, or "PUBLIC". */
        public string $role,
        /** What it is about: an attribute, a membership, "schema vortos", "table vortos.audit_events". */
        public string $subject,
        /** The departure in privilege words, e.g. "has TRUNCATE", "lacks USAGE". */
        public string $detail,
    ) {}

    /** @return array{kind: string, role: string, subject: string, detail: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'role' => $this->role, 'subject' => $this->subject, 'detail' => $this->detail];
    }
}
