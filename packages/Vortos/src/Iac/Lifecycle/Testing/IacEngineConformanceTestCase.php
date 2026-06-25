<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Testing;

use Vortos\Iac\Lifecycle\IacEngineCapability;
use Vortos\Iac\Lifecycle\IacEngineInterface;
use Vortos\Iac\Lifecycle\IacExecutionContext;
use Vortos\Iac\Lifecycle\IacWorkspace;
use Vortos\OpsKit\Testing\ConformanceTestCase;

abstract class IacEngineConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createEngine(): IacEngineInterface;

    protected function createDriver(): IacEngineInterface
    {
        return $this->createEngine();
    }

    final public function test_init_is_idempotent(): void
    {
        $engine = $this->createEngine();
        $ws = $this->createTestWorkspace();
        $ctx = new IacExecutionContext();

        $engine->init($ws, $ctx);
        $engine->init($ws, $ctx);

        $this->addToAssertionCount(1);
    }

    final public function test_direct_provision_is_false(): void
    {
        $descriptor = $this->createEngine()->capabilities();
        $this->assertFalse(
            $descriptor->supports(IacEngineCapability::DirectProvision),
            'IaC engines must declare direct_provision=false.',
        );
    }

    final public function test_plan_file_capability_is_true(): void
    {
        $descriptor = $this->createEngine()->capabilities();
        $this->assertTrue(
            $descriptor->supports(IacEngineCapability::PlanFile),
            'IaC engines must support plan_file.',
        );
    }

    final public function test_json_output_capability_is_true(): void
    {
        $descriptor = $this->createEngine()->capabilities();
        $this->assertTrue(
            $descriptor->supports(IacEngineCapability::JsonOutput),
            'IaC engines must support json_output.',
        );
    }

    abstract protected function createTestWorkspace(): IacWorkspace;
}
