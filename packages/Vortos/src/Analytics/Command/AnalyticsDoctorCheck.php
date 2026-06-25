<?php

declare(strict_types=1);

namespace Vortos\Analytics\Command;

use Vortos\Analytics\Registry\AnalyticsDriverRegistry;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Guarded `deploy:doctor` integration (only registered when `vortos-deploy` is
 * installed — {@see \Vortos\Analytics\DependencyInjection\AnalyticsExtension}):
 * verifies the configured analytics driver key is actually registered, **without
 * printing any secret material** (no DSN/API key value ever appears in a finding).
 */
final class AnalyticsDoctorCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly AnalyticsDriverRegistry $registry,
        private readonly string $configuredDriverKey,
    ) {}

    public function id(): string
    {
        return 'analytics.driver_configured';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        if ($this->configuredDriverKey === '') {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'ANALYTICS_DRIVER is empty.',
                remediation: 'Set ANALYTICS_DRIVER to a registered key (e.g. "null").',
            );
        }

        if (!$this->registry->has($this->configuredDriverKey)) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf("Configured analytics driver '%s' is not registered.", $this->configuredDriverKey),
                detail: 'Known drivers: ' . implode(', ', $this->registry->keys()),
                remediation: 'Install the matching split package or fix ANALYTICS_DRIVER.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf("Analytics driver '%s' is registered.", $this->configuredDriverKey),
        );
    }
}
