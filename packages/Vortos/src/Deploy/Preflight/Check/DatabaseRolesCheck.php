<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Migration\Access\DatabaseRoleConformanceInspector;
use Vortos\Migration\Access\DatabaseRoleConformanceStatus;
use Vortos\Migration\Access\DatabaseRoleViolation;
use Vortos\OpsKit\Gate\GateDisposition;

/**
 * Reports, on every deploy, whether the live database holds the declared least-privilege role model.
 *
 * ADVISORY ({@see disposition()}): roles, attributes and ownership are cluster state the release neither sets nor
 * worsens — the superuser tier is an operator step, and the owner-tier grants run in this same deploy after the
 * doctor. Vetoing here would hold every unrelated fix hostage to a database change only an operator can make (the
 * C14/C15 deadlock class). The same condition pages continuously through the database-role-conformance probe.
 */
final class DatabaseRolesCheck implements PreflightCheckInterface
{
    public function __construct(private readonly ?DatabaseRoleConformanceInspector $inspector) {}

    public function id(): string
    {
        return 'persistence.database_roles';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Security;
    }

    public function disposition(): GateDisposition
    {
        return GateDisposition::Advisory;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $report = $this->inspector?->inspect();
        if ($report === null || $report->status === DatabaseRoleConformanceStatus::Undeclared) {
            return PreflightFinding::skip($this->id(), $this->category(), 'no database role model declared');
        }

        return match ($report->status) {
            DatabaseRoleConformanceStatus::Conforming => PreflightFinding::pass($this->id(), $this->category(), 'The database holds exactly the declared least-privilege roles'),
            DatabaseRoleConformanceStatus::Indeterminate => PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'The database roles could not be checked against the declared model.',
                (string) $report->reason,
                'Run vortos:database:roles:check on a node connected to the governed database.',
            ),
            default => PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('The database departs from the declared least-privilege role model (%d finding(s)).', \count($report->violations)),
                implode('; ', array_map(static fn (DatabaseRoleViolation $v): string => sprintf('%s %s %s: %s', $v->kind->value, $v->role, $v->subject, $v->detail), \array_slice($report->violations, 0, 20))),
                'Run vortos:database:roles:sql --authority=superuser (operator, local socket) and vortos:database:roles:grant (owner), then vortos:database:roles:check.',
            ),
        };
    }
}
