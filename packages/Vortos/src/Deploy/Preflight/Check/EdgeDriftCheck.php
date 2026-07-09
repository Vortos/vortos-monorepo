<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Cutover\Drift\EdgeDriftDetector;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Doctor gate: is the live edge still serving what the framework recorded?
 *
 * Compares the live admin upstream and the on-disk boot file against the durable edge state. A manual
 * admin push, a stale boot file, or an unreachable admin surfaces here as a Fail — the exact class of
 * problem behind the original incident (live route diverging from durable intent). Read-only: it only
 * reads state, live config, and the boot file (alerting is the separate deploy:edge:drift command's
 * job, so this stays side-effect-free per the doctor contract).
 *
 * Skips when no edge state has been recorded yet (the edge has never cut over — nothing to drift from).
 */
final class EdgeDriftCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly EdgeDriftDetector $detector,
    ) {}

    public function id(): string
    {
        return 'edge.drift';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        try {
            $report = $this->detector->detect($context->environment->value);
        } catch (\Throwable $e) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'edge drift check could not complete',
                $e->getMessage(),
                'Ensure the edge admin API is reachable, then re-run the doctor.',
            );
        }

        if (!$report->hasState) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                'no recorded edge state yet; nothing to check for drift',
            );
        }

        if ($report->inSync) {
            return PreflightFinding::pass($this->id(), $this->category(), $report->summary());
        }

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            'the live edge has drifted from the recorded routing intent',
            $report->summary(),
            'Redeploy to reconverge the edge (the cutover rewrites the live config and the durable '
            . 'boot file), or investigate the manual admin change.',
        );
    }
}
