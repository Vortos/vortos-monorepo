<?php

declare(strict_types=1);

namespace Vortos\Alerts\Preflight;

use Vortos\Alerts\Integration\Audit\AlertAuditRecorderInterface;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Fails the deploy when the alert audit ledger cannot record.
 *
 * WHY THIS CHECK EXISTS
 *
 * The ledger recorded nothing in production for as long as it had been deployed. Two decisions
 * combined to make that invisible:
 *
 *   1. The recorder was registered only when a compile-time `$_ENV` read saw the signing key. The
 *      container is compiled in a clean environment, so the key read as empty and the service was
 *      omitted — on a host where the key was in fact present.
 *   2. The dispatcher treats a missing recorder as "auditing not configured" and skips it, and
 *      swallows recording failures on purpose, because losing a page is worse than losing its
 *      ledger entry.
 *
 * Both decisions are individually defensible and together they produced an audit trail with zero
 * rows, no error, and no signal, while alerts were being delivered normally. Nothing in the alert
 * path can detect this, because the alert path is deliberately indifferent to it. So it is asked
 * here instead, once, at deploy time, where an answer of "no" stops the release.
 */
final class AlertAuditLedgerCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly ?AlertAuditRecorderInterface $recorder = null,
    ) {}

    public function id(): string
    {
        return 'alerts.audit_ledger';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        if ($this->recorder === null) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'Alert audit recorder is not registered.',
                'AlertDispatcher will skip recording entirely, so no record of what was paged will exist.',
                'Ensure vortos-alerts is wired and the audit ledger services are registered.',
            );
        }

        if (!$this->recorder->isOperational()) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'Alert audit ledger cannot sign entries — it is not recording.',
                'ALERTS_AUDIT_HMAC_KEY resolves to an empty value, so notification and '
                . 'acknowledgement entries cannot be written. Alerts still deliver; the '
                . 'tamper-evident record of who was paged does not exist.',
                'Set ALERTS_AUDIT_HMAC_KEY in the deployment environment and redeploy.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            'Alert audit ledger is recording.',
        );
    }
}
