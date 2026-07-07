<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Plan\ImagePrunePolicy;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;

final class StepExecutorReclaimTest extends TestCase
{
    private FakeCommandRunner $runner;
    private StepExecutor $executor;

    protected function setUp(): void
    {
        $this->runner = new FakeCommandRunner();
        $this->executor = new StepExecutor(
            stateStore: new FakeDeployStateStore(),
            registry: new FakeContainerRegistry(),
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: $this->runner,
        );
    }

    public function test_disabled_policy_runs_no_docker_commands(): void
    {
        $summary = $this->executor->reclaimImages($this->image(), ImagePrunePolicy::disabled());

        $this->assertSame('image reclaim disabled', $summary);
        $this->assertCount(0, $this->runner->calls);
    }

    public function test_keeps_active_and_previous_and_removes_older(): void
    {
        // docker images newest-first: active, previous, then two superseded.
        $this->runner->addResult(new CommandResult(0, "sha-active\nsha-prev\nsha-old1\nsha-old2\n", '', 0.01));

        $summary = $this->executor->reclaimImages($this->image(), new ImagePrunePolicy(keep: 2));

        $argvs = array_map(static fn (array $c): array => $c['argv'], $this->runner->calls);

        // First call lists the repository's images.
        $this->assertSame(['docker', 'images', 'ghcr.io/acme/app', '--no-trunc', '--format', '{{.ID}}'], $argvs[0]);
        // The two superseded IDs are removed individually (never a blanket -a), active+previous kept.
        $this->assertContains(['docker', 'image', 'rm', 'sha-old1'], $argvs);
        $this->assertContains(['docker', 'image', 'rm', 'sha-old2'], $argvs);
        $this->assertNotContains(['docker', 'image', 'rm', 'sha-active'], $argvs);
        $this->assertNotContains(['docker', 'image', 'rm', 'sha-prev'], $argvs);
        // Dangling + build-cache pruned.
        $this->assertContains(['docker', 'image', 'prune', '-f'], $argvs);
        $this->assertContains(['docker', 'builder', 'prune', '-f', '--filter', 'until=168h'], $argvs);
        $this->assertStringContainsString('removed 2 superseded image(s)', $summary);
    }

    public function test_in_use_image_removal_failure_does_not_throw(): void
    {
        $this->runner->addResult(new CommandResult(0, "sha-active\nsha-prev\nsha-old1\n", '', 0.01));
        // rm of the superseded image "fails" (e.g. still referenced) — exit non-zero, no exception.
        $this->runner->addResult(new CommandResult(1, '', 'image is being used by running container', 0.01));

        $summary = $this->executor->reclaimImages($this->image(), new ImagePrunePolicy(keep: 2));

        $this->assertStringContainsString('removed 0 superseded image(s)', $summary);
        // Still proceeds to dangling + builder prune.
        $argvs = array_map(static fn (array $c): array => $c['argv'], $this->runner->calls);
        $this->assertContains(['docker', 'image', 'prune', '-f'], $argvs);
    }

    public function test_nothing_to_remove_when_within_keep(): void
    {
        $this->runner->addResult(new CommandResult(0, "sha-active\nsha-prev\n", '', 0.01));

        $summary = $this->executor->reclaimImages($this->image(), new ImagePrunePolicy(keep: 2));

        $argvs = array_map(static fn (array $c): array => $c['argv'], $this->runner->calls);
        foreach ($argvs as $argv) {
            $this->assertNotSame('rm', $argv[2] ?? null, 'nothing should be removed when within keep');
        }
        $this->assertStringContainsString('removed 0 superseded image(s)', $summary);
    }

    private function image(): ImageReference
    {
        return new ImageReference('ghcr.io/acme/app', digest: 'sha256:' . str_repeat('ab', 32));
    }
}
