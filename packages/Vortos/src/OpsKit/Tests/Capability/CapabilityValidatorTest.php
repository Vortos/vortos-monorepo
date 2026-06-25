<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Capability;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Capability\CapabilityMismatchException;
use Vortos\OpsKit\Driver\Capability\CapabilityValidator;
use Vortos\OpsKit\Driver\Capability\RequiredCapabilities;

final class CapabilityValidatorTest extends TestCase
{
    public function test_passes_silently_when_driver_satisfies_requirements(): void
    {
        $descriptor = CapabilityDescriptor::create(['rolling_across_nodes' => true], ['max_nodes' => 3]);
        $required = RequiredCapabilities::of(['rolling_across_nodes'], ['max_nodes' => 3]);

        CapabilityValidator::assertSatisfies('k8s', 'deploy', $descriptor, $required);
        $this->expectNotToPerformAssertions();
    }

    public function test_fails_at_config_time_with_actionable_message(): void
    {
        // The reference failure: select 'rolling' on a single-VPS target.
        $descriptor = CapabilityDescriptor::create(['rolling_across_nodes' => false], ['max_nodes' => 1]);
        $required = RequiredCapabilities::of(['rolling_across_nodes']);

        try {
            CapabilityValidator::assertSatisfies('ssh-compose', 'deploy', $descriptor, $required);
            $this->fail('Expected CapabilityMismatchException');
        } catch (CapabilityMismatchException $e) {
            $this->assertStringContainsString('ssh-compose', $e->getMessage());
            $this->assertStringContainsString('rolling_across_nodes', $e->getMessage());
            $this->assertSame(['rolling_across_nodes'], $e->mismatch->missingCapabilities);
        }
    }
}
