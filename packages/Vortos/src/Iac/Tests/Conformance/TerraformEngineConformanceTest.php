<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Conformance;

use Vortos\Iac\Driver\Terraform\BinaryResolver;
use Vortos\Iac\Driver\Terraform\PlanJsonParser;
use Vortos\Iac\Driver\Terraform\ProcessOutcome;
use Vortos\Iac\Driver\Terraform\ProcessRunnerInterface;
use Vortos\Iac\Driver\Terraform\TerraformEngine;
use Vortos\Iac\Lifecycle\IacEngineCapability;
use Vortos\Iac\Lifecycle\IacEngineInterface;
use Vortos\Iac\Lifecycle\IacWorkspace;
use Vortos\Iac\Lifecycle\Testing\IacEngineConformanceTestCase;

final class TerraformEngineConformanceTest extends IacEngineConformanceTestCase
{
    protected function createEngine(): IacEngineInterface
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome
            {
                if ($argv[0] === 'which') {
                    return new ProcessOutcome(0, "/usr/bin/tofu\n", '', 1);
                }
                if (in_array('version', $argv, true)) {
                    return new ProcessOutcome(0, '{"terraform_version":"1.8.0"}', '', 1);
                }
                if (in_array('init', $argv, true)) {
                    return new ProcessOutcome(0, '', '', 10);
                }
                if (in_array('plan', $argv, true)) {
                    return new ProcessOutcome(0, '', '', 10);
                }
                if (in_array('show', $argv, true)) {
                    return new ProcessOutcome(0, json_encode(['resource_changes' => []]), '', 10);
                }
                return new ProcessOutcome(0, '', '', 10);
            }
        };

        return new TerraformEngine($runner, new BinaryResolver($runner), new PlanJsonParser());
    }

    protected function expectedKey(): string
    {
        return 'terraform';
    }

    protected function createTestWorkspace(): IacWorkspace
    {
        return IacWorkspace::forEnvironment('dev', sys_get_temp_dir());
    }

    public function test_remote_state_capability(): void
    {
        $desc = $this->createEngine()->capabilities();
        $this->assertTrue($desc->supports(IacEngineCapability::RemoteState));
        $this->assertTrue($desc->supports(IacEngineCapability::StateLocking));
    }

    public function test_binary_info_in_constraints(): void
    {
        $desc = $this->createEngine()->capabilities();
        $this->assertSame('tofu', $desc->constraint('binary'));
        $this->assertSame('1.8.0', $desc->constraint('version'));
    }
}
