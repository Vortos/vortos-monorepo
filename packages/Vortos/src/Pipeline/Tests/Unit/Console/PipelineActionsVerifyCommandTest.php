<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Pipeline\Console\PipelineActionsVerifyCommand;
use Vortos\Pipeline\Verification\ActionPinVerifier;
use Vortos\Pipeline\Verification\ActionRefResolverInterface;
use Vortos\Pipeline\Verification\ActionRuntimeResolverInterface;

final class PipelineActionsVerifyCommandTest extends TestCase
{
    public function test_succeeds_when_all_pins_exist_and_run_on_a_supported_runtime(): void
    {
        $tester = new CommandTester($this->command('node24'));

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('supported runtime', $tester->getDisplay());
    }

    public function test_fails_closed_when_any_pin_runs_on_a_removed_runtime(): void
    {
        $tester = new CommandTester($this->command('node16'));

        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('removed runtime', $tester->getDisplay());
    }

    public function test_deprecated_runtime_passes_with_advisory(): void
    {
        $tester = new CommandTester($this->command('node20'));

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('deprecated runtime', $tester->getDisplay());
    }

    public function test_json_output_carries_runtime_verdicts(): void
    {
        $tester = new CommandTester($this->command('node16'));

        $tester->execute(['--json' => true]);
        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($decoded['ok']);
        $this->assertTrue($decoded['all_exist']);
        $this->assertFalse($decoded['all_runtimes_supported']);
        $this->assertSame('node16', $decoded['pins'][0]['runtime']);
    }

    private function command(string $runtime): PipelineActionsVerifyCommand
    {
        // Echo resolver: every ref resolves to itself, so all pins "exist".
        $refResolver = new class implements ActionRefResolverInterface {
            public function resolve(string $owner, string $repo, string $ref): ?string
            {
                return $ref;
            }
        };
        $runtimeResolver = new class ($runtime) implements ActionRuntimeResolverInterface {
            public function __construct(private string $runtime) {}

            public function runtime(string $owner, string $repo, string $ref): ?string
            {
                return $this->runtime;
            }
        };

        return new PipelineActionsVerifyCommand(new ActionPinVerifier($refResolver, $runtimeResolver));
    }
}
