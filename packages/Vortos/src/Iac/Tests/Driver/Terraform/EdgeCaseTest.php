<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Driver\Terraform;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\BinaryResolver;
use Vortos\Iac\Driver\Terraform\PlanJsonParser;
use Vortos\Iac\Driver\Terraform\ProcessOutcome;
use Vortos\Iac\Driver\Terraform\ProcessRunnerInterface;
use Vortos\Iac\Driver\Terraform\SecretRedactor;
use Vortos\Iac\Driver\Terraform\SystemProcessRunner;
use Vortos\Iac\Driver\Terraform\TerraformEngine;
use Vortos\Iac\Exception\IacException;
use Vortos\Iac\Lifecycle\IacExecutionContext;
use Vortos\Iac\Lifecycle\IacWorkspace;

final class EdgeCaseTest extends TestCase
{
    public function test_binary_nonzero_exit_throws(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            private int $call = 0;
            public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome
            {
                $this->call++;
                if ($argv[0] === 'which') {
                    return new ProcessOutcome(0, "/usr/bin/tofu\n", '', 1);
                }
                if (in_array('version', $argv, true)) {
                    return new ProcessOutcome(0, '{"terraform_version":"1.8.0"}', '', 1);
                }
                if (in_array('init', $argv, true)) {
                    return new ProcessOutcome(0, '', '', 10);
                }
                return new ProcessOutcome(1, '', 'Error: provider error', 100);
            }
        };

        $engine = new TerraformEngine($runner, new BinaryResolver($runner), new PlanJsonParser());
        $ws = IacWorkspace::forEnvironment('dev', sys_get_temp_dir());

        $this->expectException(IacException::class);
        $this->expectExceptionMessage('terraform plan failed');
        $engine->plan($ws, new IacExecutionContext());
    }

    public function test_output_over_1mib_is_truncated(): void
    {
        $runner = new SystemProcessRunner();
        $outcome = $runner->run(['dd', 'if=/dev/zero', 'bs=1048577', 'count=1', 'status=none'], '/', ['PATH' => '/usr/bin:/bin'], 10);
        $this->assertLessThanOrEqual(1_048_576, strlen($outcome->stdout), 'stdout must be bounded to 1 MiB');
    }

    public function test_secret_redaction_multiline(): void
    {
        $secret = "multi\nline\nsecret";
        $redactor = new SecretRedactor([$secret]);
        $this->assertSame('output: ***', $redactor->redact("output: multi\nline\nsecret"));
    }

    public function test_secret_redaction_partial_substring(): void
    {
        $redactor = new SecretRedactor(['SECRET_TOKEN']);
        $input = 'prefix-SECRET_TOKEN-suffix and SECRET_TOKEN again';
        $this->assertSame('prefix-***-suffix and *** again', $redactor->redact($input));
    }

    public function test_env_allowlist_only_passes_listed_vars(): void
    {
        $ctx = new IacExecutionContext(envAllowlist: ['HOME']);
        $this->assertContains('HOME', $ctx->envAllowlist);
        $this->assertNotContains('RANDOM_VAR', $ctx->envAllowlist);
    }

    public function test_unknown_env_name_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IacWorkspace::forEnvironment('INVALID!', '/tmp');
    }

    public function test_path_traversal_rejected_by_policy(): void
    {
        $this->expectException(\Vortos\Iac\Exception\PathViolationException::class);
        \Vortos\Iac\Export\PathPolicy::validate('../../../etc/passwd.tf.json');
    }

    public function test_plan_json_malformed_throws(): void
    {
        $parser = new PlanJsonParser();
        $this->expectException(\JsonException::class);
        $parser->parse('{bad json', '/tmp/p.bin');
    }

    public function test_plan_json_empty_throws(): void
    {
        $parser = new PlanJsonParser();
        $this->expectException(IacException::class);
        $parser->parse('', '/tmp/p.bin');
    }

    public function test_audit_sink_absent_no_error(): void
    {
        $sink = new \Vortos\Iac\Lifecycle\Audit\NullIacAuditSink();
        $event = new \Vortos\Iac\Lifecycle\Audit\LifecycleEvent(
            \Vortos\Iac\Lifecycle\LifecyclePhase::Apply,
            'dev', 'digest', 'user', 'summary', '1.0', '2026-01-01T00:00:00+00:00',
        );
        $sink->record($event);
        $this->assertTrue(true, 'Null audit sink must not throw.');
    }
}
