<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class EnforcementCompletenessTest extends TestCase
{
    public function test_every_strategy_with_stage_color_cannot_bypass_phase_gate(): void
    {
        $strategyDir = dirname(__DIR__, 2) . '/Strategy';
        $this->assertDirectoryExists($strategyDir);

        $plannerFile = dirname(__DIR__, 2) . '/Plan/DeployPlanner.php';
        $plannerCode = file_get_contents($plannerFile);

        $this->assertStringContainsString(
            'assertNoPendingContract',
            $plannerCode,
            'DeployPlanner must call PhaseGate::assertNoPendingContract() before delegating to any strategy.',
        );

        foreach ($this->phpFiles($strategyDir) as $file) {
            $code = (string) file_get_contents($file);
            $basename = basename($file);

            if (!str_contains($code, 'implements DeployStrategyInterface')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'ContractGuard',
                $code,
                "Strategy {$basename} must not carry a ContractGuard placeholder — the gate is enforced in the planner.",
            );
        }
    }

    public function test_executor_has_defense_in_depth_contract_check(): void
    {
        $executorFile = dirname(__DIR__, 2) . '/Driver/SshCompose/StepExecutor.php';
        $code = file_get_contents($executorFile);

        $this->assertStringContainsString(
            'ContractInSameDeployException',
            $code,
            'StepExecutor must re-assert no pending contract as defense-in-depth.',
        );
    }

    public function test_switch_upstream_routes_through_cutover_coordinator(): void
    {
        $executorFile = dirname(__DIR__, 2) . '/Driver/SshCompose/StepExecutor.php';
        $code = file_get_contents($executorFile);

        $this->assertStringContainsString(
            'CutoverCoordinator',
            $code,
            'StepExecutor::handleSwitchUpstream must route through CutoverCoordinator (Block 9).',
        );

        $this->assertStringNotContainsString(
            'real cutover = Block 9',
            $code,
            'StepExecutor::handleSwitchUpstream stub must be replaced by the real coordinator.',
        );
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
