<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Deploy\Console\DeployCommand;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Runner\DeployRunner;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeAppliedMigrationSetReader;
use Vortos\Deploy\Tests\Fixtures\FakeManifestReadModel;
use Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Deploy\Tests\Fixtures\RecordingDeployTarget;
use Vortos\Release\Schema\SchemaFingerprint;

final class DeployCommandTest extends TestCase
{
    use PreflightTestFactory;

    public function test_doctor_red_exits_one_and_deploys_nothing(): void
    {
        $target = new RecordingDeployTarget();
        $tester = new CommandTester(new DeployCommand($this->runner($target, doctorClear: false)));

        $exit = $tester->execute(['--env' => 'production', '--yes' => true]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, $target->releaseCalls);
        $this->assertStringContainsString('REFUSED', $tester->getDisplay());
    }

    public function test_dry_run_exits_zero_and_prints_plan(): void
    {
        $target = new RecordingDeployTarget();
        $tester = new CommandTester(new DeployCommand($this->runner($target, doctorClear: true)));

        $exit = $tester->execute(['--env' => 'production', '--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $target->releaseCalls);
        $this->assertStringContainsString('Deploy Plan', $tester->getDisplay());
        $this->assertStringContainsString('DRY RUN', $tester->getDisplay());
    }

    public function test_prod_without_yes_wrong_token_aborts(): void
    {
        $target = new RecordingDeployTarget();
        $tester = new CommandTester(new DeployCommand($this->runner($target, doctorClear: true)));
        $tester->setInputs(['not-the-env']);

        $exit = $tester->execute(['--env' => 'production']);

        $this->assertSame(1, $exit);
        $this->assertSame(0, $target->releaseCalls, 'a fat-fingered prod confirmation must not deploy');
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
    }

    public function test_prod_correct_token_deploys(): void
    {
        $target = new RecordingDeployTarget();
        $tester = new CommandTester(new DeployCommand($this->runner($target, doctorClear: true)));
        $tester->setInputs(['production']);

        $exit = $tester->execute(['--env' => 'production']);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $target->releaseCalls);
    }

    public function test_yes_skips_prompt_and_deploys(): void
    {
        $target = new RecordingDeployTarget();
        $tester = new CommandTester(new DeployCommand($this->runner($target, doctorClear: true)));

        $exit = $tester->execute(['--env' => 'production', '--yes' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $target->releaseCalls);
    }

    public function test_json_refused_writes_machine_json_to_stdout_and_reason_to_stderr(): void
    {
        $target = new RecordingDeployTarget();
        $tester = new CommandTester(new DeployCommand($this->runner($target, doctorClear: false)));

        $exit = $tester->execute(
            ['--env' => 'production', '--yes' => true, '--json' => true],
            ['capture_stderr_separately' => true],
        );

        $this->assertSame(1, $exit);

        // stdout is pure, parseable JSON (no human noise mixed in).
        $stdout = $tester->getDisplay();
        $decoded = json_decode(trim($stdout), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('refused', $decoded['status']);

        // stderr carries the failing gate + reason so CI logs are self-explanatory (R8-3).
        $stderr = $tester->getErrorOutput();
        $this->assertStringContainsString('REFUSED: stub — blocked', $stderr);
        $this->assertStringContainsString('Deploy refused for production', $stderr);
    }

    private function runner(RecordingDeployTarget $target, bool $doctorClear): DeployRunner
    {
        $targets = new DeployTargetRegistry(new InMemoryServiceLocator(['fake-target' => $target]));
        $manifest = $this->manifest(['m001']);
        $manifests = new FakeManifestReadModel(
            latest: $manifest,
            previous: $manifest,
            applied: new SchemaFingerprint(['m001']),
            knownIds: ['m001'],
        );

        $factory = new PreflightContextFactory($this->resolver(), $manifests, $this->stateBuilder(['m001']), $targets);
        $guard = new RollbackGuard(new FakeAppliedMigrationSetReader(new SchemaFingerprint(['m001'])), $manifests);
        $rollback = new RollbackRunner($this->resolver(), $manifests, $guard, $targets, $this->stateBuilder(['m001']), new PlanRenderer());

        $check = new class($doctorClear) implements PreflightCheckInterface {
            public function __construct(private readonly bool $clear) {}

            public function id(): string
            {
                return 'stub';
            }

            public function category(): PreflightCategory
            {
                return PreflightCategory::DriverSet;
            }

            public function check(PreflightContext $context): PreflightFinding
            {
                return $this->clear
                    ? PreflightFinding::pass('stub', PreflightCategory::DriverSet, 'ok')
                    : PreflightFinding::fail('stub', PreflightCategory::DriverSet, 'blocked');
            }
        };

        return new DeployRunner($factory, $targets, new PlanRenderer(), new DeployDoctor([$check]), $rollback);
    }
}
