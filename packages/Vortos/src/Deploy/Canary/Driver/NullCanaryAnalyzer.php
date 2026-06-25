<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary\Driver;

use Vortos\Deploy\Canary\CanaryAnalysisRequest;
use Vortos\Deploy\Canary\CanaryAnalyzerInterface;
use Vortos\Deploy\Canary\CanaryDecision;
use Vortos\Deploy\Canary\CanaryVerdict;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * No-op analyzer — always returns Progress with no evidence.
 *
 * Valid ONLY for non-canary strategies (local dev, blue-green targets). The
 * CanaryAnalyzerReadyCheck preflight gate refuses this driver on strategy('canary').
 */
#[AsDriver('null')]
final class NullCanaryAnalyzer implements CanaryAnalyzerInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([]);
    }

    public function analyze(CanaryAnalysisRequest $request): CanaryVerdict
    {
        return new CanaryVerdict(
            decision: CanaryDecision::Progress,
            evaluations: [],
            reason: 'null analyzer — no metrics checked',
            totalSamples: 0,
            at: $request->at,
        );
    }
}
