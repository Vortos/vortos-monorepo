<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

final readonly class DatabaseRoleConformanceReport
{
    /** @param list<DatabaseRoleViolation> $violations */
    private function __construct(
        public DatabaseRoleConformanceStatus $status,
        public array $violations,
        /** Why the check could not reach a verdict; an exception CLASS, never its message (it may carry a DSN). */
        public ?string $reason,
        /** False when this connection's role cannot see other sessions, so superuser connections were not counted. */
        public bool $connectionsChecked,
    ) {}

    /** @param list<DatabaseRoleViolation> $violations */
    public static function of(array $violations, bool $connectionsChecked): self
    {
        return new self(
            $violations === [] ? DatabaseRoleConformanceStatus::Conforming : DatabaseRoleConformanceStatus::Violated,
            $violations,
            null,
            $connectionsChecked,
        );
    }

    public static function undeclared(): self
    {
        return new self(DatabaseRoleConformanceStatus::Undeclared, [], 'no database role model is declared', false);
    }

    public static function indeterminate(string $reason): self
    {
        return new self(DatabaseRoleConformanceStatus::Indeterminate, [], $reason, false);
    }

    /**
     * The flat, scalar detail a health probe carries (ProbeResult's contract): findings as one capped string, so an
     * alert payload stays bounded however far the database has drifted.
     *
     * @return array<string, bool|int|string>
     */
    public function toProbeDetail(): array
    {
        $lines = array_map(
            static fn (DatabaseRoleViolation $v): string => sprintf('%s %s %s: %s', $v->kind->value, $v->role, $v->subject, $v->detail),
            \array_slice($this->violations, 0, 20),
        );

        return [
            'status' => $this->status->value,
            'reason' => (string) $this->reason,
            'connections_checked' => $this->connectionsChecked,
            'violation_count' => \count($this->violations),
            'violations' => implode('; ', $lines) . (\count($this->violations) > 20 ? '; …' : ''),
        ];
    }

    /** @return array{status: string, reason: string|null, connections_checked: bool, violations: list<array{kind: string, role: string, subject: string, detail: string}>} */
    public function toDetail(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason,
            'connections_checked' => $this->connectionsChecked,
            'violations' => array_map(static fn (DatabaseRoleViolation $v): array => $v->toArray(), $this->violations),
        ];
    }
}
