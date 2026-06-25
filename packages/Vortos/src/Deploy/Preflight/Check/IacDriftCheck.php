<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Iac\Lifecycle\IacDriftAuditorInterface;

final class IacDriftCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly IacDriftAuditorInterface $auditor,
    ) {}

    public function id(): string
    {
        return 'iac.drift';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Plan;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        try {
            $report = $this->auditor->audit($context->environment->value);
        } catch (\Throwable $e) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'infrastructure drift check failed (unreachable)',
                $e->getMessage(),
                'Ensure the IaC working directory and backend are accessible.',
            );
        }

        if ($report->unreachable) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'infrastructure drift check unreachable',
                $report->summary,
                'Ensure the IaC working directory and backend are accessible.',
            );
        }

        if (!$report->hasDrift) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'no infrastructure drift detected',
            );
        }

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            'infrastructure drift detected',
            $report->summary,
            'Resolve drift by running vortos:iac:apply or investigating out-of-band infrastructure changes before deploying.',
        );
    }
}
