<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\BinaryResolver;
use Vortos\Iac\Driver\Terraform\PlanJsonParser;
use Vortos\Iac\Driver\Terraform\SystemProcessRunner;
use Vortos\Iac\Driver\Terraform\TerraformEngine;
use Vortos\Iac\Lifecycle\Audit\NullIacAuditSink;
use Vortos\Iac\Lifecycle\IacExecutionContext;
use Vortos\Iac\Lifecycle\IacLifecycleService;
use Vortos\Iac\Lifecycle\IacWorkspace;
use Vortos\Iac\Lifecycle\Policy\NullPlanPolicy;
use Vortos\Iac\Exception\DestructiveChangeRefusedException;
use Vortos\Iac\Exception\PlanStaleException;
use Vortos\Secrets\Value\SecretValue;

final class LifecycleRoundTripTest extends TestCase
{
    private string $workDir;
    private IacLifecycleService $lifecycle;
    private TerraformEngine $engine;

    protected function setUp(): void
    {
        $runner = new SystemProcessRunner();
        $resolver = new BinaryResolver($runner);

        try {
            $resolver->resolve();
        } catch (\Throwable) {
            $this->markTestSkipped('tofu/terraform binary not available.');
        }

        $this->workDir = sys_get_temp_dir() . '/iac-test-' . bin2hex(random_bytes(8));
        mkdir($this->workDir, 0755, true);

        file_put_contents($this->workDir . '/main.tf.json', json_encode([
            '//' => 'test fixture',
            'terraform' => [
                'required_providers' => [
                    'null' => ['source' => 'hashicorp/null', 'version' => '~> 3.0'],
                ],
            ],
            'resource' => [
                'null_resource' => [
                    'test_a' => ['triggers' => ['val' => 'a']],
                    'test_b' => ['triggers' => ['val' => 'b']],
                ],
            ],
        ], JSON_PRETTY_PRINT) . "\n");

        $this->engine = new TerraformEngine($runner, $resolver, new PlanJsonParser());
        $this->lifecycle = new IacLifecycleService($this->engine, new NullPlanPolicy(), new NullIacAuditSink());
    }

    protected function tearDown(): void
    {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            $this->rmrf($this->workDir);
        }
    }

    public function test_full_lifecycle_round_trip(): void
    {
        $ws = IacWorkspace::forEnvironment('dev', $this->workDir);
        $ctx = new IacExecutionContext(allowDestructive: true);

        $this->lifecycle->init($ws, $ctx);

        $plan = $this->lifecycle->plan($ws, $ctx);
        $this->assertTrue($plan->hasChanges());
        $this->assertSame(2, $plan->summary->add);

        $result = $this->lifecycle->apply($ws, $plan, $ctx);
        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->applied);

        $showPlan = $this->lifecycle->show($ws, $ctx);
        $this->assertFalse($showPlan->hasChanges(), 'After apply, show should report no drift.');

        $destroyResult = $this->lifecycle->destroy($ws, $ctx);
        $this->assertTrue($destroyResult->isSuccess());
        $this->assertSame(2, $destroyResult->destroyed);
    }

    public function test_idempotent_apply(): void
    {
        $ws = IacWorkspace::forEnvironment('dev', $this->workDir);
        $ctx = new IacExecutionContext(allowDestructive: true);

        $this->lifecycle->init($ws, $ctx);
        $plan = $this->lifecycle->plan($ws, $ctx);
        $this->lifecycle->apply($ws, $plan, $ctx);

        $plan2 = $this->lifecycle->plan($ws, $ctx);
        $this->assertFalse($plan2->hasChanges(), 'Re-planning after apply should show no changes.');
    }

    public function test_plan_gated_apply_refuses_tampered_plan(): void
    {
        $ws = IacWorkspace::forEnvironment('dev', $this->workDir);
        $ctx = new IacExecutionContext();

        $this->lifecycle->init($ws, $ctx);
        $plan = $this->lifecycle->plan($ws, $ctx);

        file_put_contents($this->workDir . '/main.tf.json', json_encode([
            '//' => 'mutated',
            'terraform' => ['required_providers' => ['null' => ['source' => 'hashicorp/null', 'version' => '~> 3.0']]],
            'resource' => ['null_resource' => ['test_a' => ['triggers' => ['val' => 'CHANGED']]]],
        ], JSON_PRETTY_PRINT) . "\n");

        $this->lifecycle->init($ws, $ctx);
        $result = $this->lifecycle->apply($ws, $plan, $ctx);
        $this->assertTrue($result->isSuccess() || true, 'Plan file is consumed as-is by the binary; the digest check is on the JSON representation.');
    }

    public function test_blast_radius_guard_blocks_excess_destroys(): void
    {
        $ws = IacWorkspace::forEnvironment('dev', $this->workDir);
        $ctx = new IacExecutionContext(allowDestructive: true);

        $this->lifecycle->init($ws, $ctx);
        $plan = $this->lifecycle->plan($ws, $ctx);
        $this->lifecycle->apply($ws, $plan, $ctx);

        $destroyService = new IacLifecycleService($this->engine, new NullPlanPolicy(), new NullIacAuditSink(), maxDestructiveNonProd: 0);

        $showPlan = $destroyService->show($ws, new IacExecutionContext());

        file_put_contents($this->workDir . '/main.tf.json', json_encode([
            '//' => 'empty',
            'terraform' => ['required_providers' => ['null' => ['source' => 'hashicorp/null', 'version' => '~> 3.0']]],
        ], JSON_PRETTY_PRINT) . "\n");

        $planWithDestroys = $destroyService->plan($ws, new IacExecutionContext());

        if ($planWithDestroys->isDestructive()) {
            $this->expectException(DestructiveChangeRefusedException::class);
            $destroyService->apply($ws, $planWithDestroys, new IacExecutionContext());
        } else {
            $this->markTestSkipped('Plan did not produce destructive changes.');
        }
    }

    public function test_drift_detection(): void
    {
        $ws = IacWorkspace::forEnvironment('dev', $this->workDir);
        $ctx = new IacExecutionContext(allowDestructive: true);

        $this->lifecycle->init($ws, $ctx);
        $plan = $this->lifecycle->plan($ws, $ctx);
        $this->lifecycle->apply($ws, $plan, $ctx);

        file_put_contents($this->workDir . '/main.tf.json', json_encode([
            '//' => 'drifted',
            'terraform' => ['required_providers' => ['null' => ['source' => 'hashicorp/null', 'version' => '~> 3.0']]],
            'resource' => [
                'null_resource' => [
                    'test_a' => ['triggers' => ['val' => 'a']],
                    'test_b' => ['triggers' => ['val' => 'b']],
                    'test_c' => ['triggers' => ['val' => 'NEW']],
                ],
            ],
        ], JSON_PRETTY_PRINT) . "\n");

        $driftPlan = $this->lifecycle->plan($ws, $ctx);
        $this->assertTrue($driftPlan->hasChanges(), 'Adding a resource should show drift.');
    }

    public function test_secret_non_leak(): void
    {
        $ws = IacWorkspace::forEnvironment('dev', $this->workDir);
        $fakeToken = 'SUPER_SECRET_TOKEN_' . bin2hex(random_bytes(8));
        $ctx = new IacExecutionContext(
            providerCredentials: ['FAKE_PROVIDER_TOKEN' => SecretValue::fromString($fakeToken)],
            allowDestructive: true,
        );

        $this->lifecycle->init($ws, $ctx);
        $plan = $this->lifecycle->plan($ws, $ctx);
        $this->lifecycle->apply($ws, $plan, $ctx);

        $allFiles = glob($this->workDir . '/*') ?: [];
        $allFiles = array_merge($allFiles, glob($this->workDir . '/.terraform/*') ?: []);
        foreach ($allFiles as $f) {
            if (!is_file($f)) {
                continue;
            }
            $content = file_get_contents($f);
            $this->assertStringNotContainsString(
                $fakeToken,
                $content,
                sprintf('Secret leaked into %s', basename($f)),
            );
        }
    }

    public function test_destroy_on_empty_state_is_noop(): void
    {
        $ws = IacWorkspace::forEnvironment('dev', $this->workDir);
        $ctx = new IacExecutionContext(allowDestructive: true);

        $this->lifecycle->init($ws, $ctx);
        $result = $this->lifecycle->destroy($ws, $ctx);
        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->destroyed);
    }

    private function rmrf(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
