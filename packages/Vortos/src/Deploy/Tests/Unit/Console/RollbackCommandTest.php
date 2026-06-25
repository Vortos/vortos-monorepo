<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Deploy\Console\RollbackCommand;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeAppliedMigrationSetReader;
use Vortos\Deploy\Tests\Fixtures\FakeManifestReadModel;
use Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Deploy\Tests\Fixtures\RecordingDeployTarget;
use Vortos\Release\Schema\SchemaFingerprint;

final class RollbackCommandTest extends TestCase
{
    use PreflightTestFactory;

    public function test_legal_rollback_exits_zero(): void
    {
        $target = new RecordingDeployTarget();
        $tester = new CommandTester(new RollbackCommand($this->runner(
            $target,
            previous: $this->manifest(['m001'], buildId: 'build-prev'),
            applied: ['m001', 'm002'],
            known: ['m001', 'm002'],
        )));

        $exit = $tester->execute(['--env' => 'production', '--yes' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $target->rollbackCalls);
        $this->assertStringContainsString('ROLLED BACK', $tester->getDisplay());
    }

    public function test_illegal_rollback_refused_with_reason(): void
    {
        $target = new RecordingDeployTarget();
        $tester = new CommandTester(new RollbackCommand($this->runner(
            $target,
            previous: $this->manifest(['m001', 'm003'], buildId: 'build-prev'),
            applied: ['m001', 'm002'],
            known: ['m001', 'm002', 'm003'],
        )));

        $exit = $tester->execute(['--env' => 'production', '--yes' => true]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, $target->rollbackCalls);
        $this->assertStringContainsString('REFUSED', $tester->getDisplay());
    }

    public function test_unknown_target_refused(): void
    {
        $tester = new CommandTester(new RollbackCommand($this->runner(
            new RecordingDeployTarget(),
            previous: null,
            applied: ['m001'],
            known: ['m001'],
        )));

        $exit = $tester->execute(['--env' => 'production', '--to' => 'nope', '--yes' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('REFUSED', $tester->getDisplay());
    }

    /**
     * @param list<string> $applied
     * @param list<string> $known
     */
    private function runner(
        RecordingDeployTarget $target,
        ?\Vortos\Release\Manifest\BuildManifest $previous,
        array $applied,
        array $known,
    ): RollbackRunner {
        $targets = new DeployTargetRegistry(new InMemoryServiceLocator(['fake-target' => $target]));
        $manifests = new FakeManifestReadModel(
            previous: $previous,
            applied: new SchemaFingerprint($applied),
            knownIds: $known,
        );
        $guard = new RollbackGuard(new FakeAppliedMigrationSetReader(new SchemaFingerprint($applied)), $manifests);

        return new RollbackRunner($this->resolver(), $manifests, $guard, $targets, $this->stateBuilder($applied), new PlanRenderer());
    }
}
