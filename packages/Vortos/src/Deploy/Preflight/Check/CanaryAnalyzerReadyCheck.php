<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Canary\CanaryAnalyzerInterface;
use Vortos\Deploy\Canary\CanaryAnalyzerRegistry;
use Vortos\Deploy\Canary\Driver\NullCanaryAnalyzer;
use Vortos\Deploy\Cutover\EdgeRouterCapability;
use Vortos\Deploy\Cutover\EdgeRouterRegistry;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Strategy\DeployStrategy;

/**
 * Fail-closed canary readiness gate (Block 22, §2.5).
 *
 * On strategy('canary') asserts:
 *  (a) edge router advertises WeightedUpstreams,
 *  (b) the wired canary analyzer is not the null driver,
 *  (c) the selected analyzer can be resolved from the registry.
 *
 * Skipped for non-canary strategies. Any throw → Fail (Block 12 discipline).
 */
final class CanaryAnalyzerReadyCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly CanaryAnalyzerRegistry $analyzers,
        private readonly EdgeRouterRegistry $routers,
    ) {}

    public function id(): string
    {
        return 'canary.analyzer-ready';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        if ($context->definition->strategy !== DeployStrategy::Canary) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                'non-canary strategy — canary analyzer check skipped',
            );
        }

        // (a) Edge router must declare WeightedUpstreams
        $routerKey = $context->definition->edgeRouter;

        if ($this->routers->has($routerKey)) {
            $router = $this->routers->router($routerKey);
            $caps = $router->capabilities();

            if (!$caps->supports(EdgeRouterCapability::WeightedUpstreams)) {
                return PreflightFinding::fail(
                    $this->id(),
                    $this->category(),
                    sprintf('edge router "%s" does not declare WeightedUpstreams capability', $routerKey),
                    'Canary strategy requires the edge router to support weighted traffic splitting.',
                    'Switch to a router that declares WeightedUpstreams=true in your driver config.',
                );
            }
        }

        // (b) A non-null canary analyzer must be wired
        $analyzerKey = $context->definition->canaryAnalyzer ?? 'null';

        if ($analyzerKey === 'null') {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'strategy("canary") requires a non-null canary analyzer',
                'The null analyzer always returns Progress without checking any metrics.',
                'Configure canaryAnalyzer: "slo-prometheus" (or another real backend) in config/deploy.php.',
            );
        }

        // (c) Analyzer must resolve
        if (!$this->analyzers->has($analyzerKey)) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('canary analyzer "%s" is not registered', $analyzerKey),
                sprintf('Registered analyzers: [%s]', implode(', ', $this->analyzers->keys())),
                'Install the analyzer package or correct the selection in config/deploy.php.',
            );
        }

        $analyzer = $this->analyzers->analyzer($analyzerKey);

        if ($analyzer instanceof NullCanaryAnalyzer) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'NullCanaryAnalyzer is forbidden on strategy("canary")',
                'The null analyzer bypasses all SLO checks and will always allow traffic ramp.',
                'Use a real analyzer such as "slo-prometheus".',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('canary analyzer "%s" is registered and ready', $analyzerKey),
        );
    }
}
