<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Reclaim;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Plan\ImagePrunePolicy;
use Vortos\Deploy\Driver\Docker\ImageReclaimer;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;

final class ImageReclaimerTest extends TestCase
{
    private const REPO = 'ghcr.io/acme/app';

    private FakeCommandRunner $runner;
    private ImageReclaimer $reclaimer;

    protected function setUp(): void
    {
        $this->runner = new FakeCommandRunner();
        $this->reclaimer = new ImageReclaimer($this->runner);
    }

    public function test_disabled_policy_runs_no_docker_commands(): void
    {
        $report = $this->reclaimer->reclaim(self::REPO, ImagePrunePolicy::disabled());

        $this->assertFalse($report->enabled);
        $this->assertSame('image reclaim disabled', $report->summary());
        $this->assertCount(0, $this->runner->calls);
    }

    public function test_keeps_recency_floor_and_removes_older(): void
    {
        // docker images newest-first: active, previous, then two superseded.
        $this->runner->addResult($this->images([
            ['sha-active', 'sha256:' . str_repeat('a', 64)],
            ['sha-prev', 'sha256:' . str_repeat('b', 64)],
            ['sha-old1', 'sha256:' . str_repeat('c', 64)],
            ['sha-old2', 'sha256:' . str_repeat('d', 64)],
        ]));
        // No containers referencing anything.
        $this->runner->addResult(new CommandResult(0, '', '', 0.01));

        $report = $this->reclaimer->reclaim(self::REPO, new ImagePrunePolicy(keep: 2));

        $argvs = $this->argvs();
        $this->assertSame(
            ['docker', 'images', self::REPO, '--no-trunc', '--format', '{{.ID}}|{{.Digest}}'],
            $argvs[0],
        );
        // The two superseded IDs are removed individually (never a blanket -a), active+previous kept.
        $this->assertContains(['docker', 'image', 'rm', 'sha-old1'], $argvs);
        $this->assertContains(['docker', 'image', 'rm', 'sha-old2'], $argvs);
        $this->assertNotContains(['docker', 'image', 'rm', 'sha-active'], $argvs);
        $this->assertNotContains(['docker', 'image', 'rm', 'sha-prev'], $argvs);
        // Dangling + build-cache pruned.
        $this->assertContains(['docker', 'image', 'prune', '-f'], $argvs);
        $this->assertContains(['docker', 'builder', 'prune', '-f', '--filter', 'until=168h'], $argvs);
        $this->assertSame(2, $report->removed);
    }

    public function test_protected_digest_is_kept_even_when_beyond_recency_floor(): void
    {
        $rollbackDigest = 'sha256:' . str_repeat('d', 64);
        $this->runner->addResult($this->images([
            ['sha-new', 'sha256:' . str_repeat('a', 64)],
            ['sha-recent', 'sha256:' . str_repeat('b', 64)],
            ['sha-junk', 'sha256:' . str_repeat('c', 64)],
            ['sha-rollback', $rollbackDigest],
        ]));
        $this->runner->addResult(new CommandResult(0, '', '', 0.01)); // no containers

        // keep=2 → recency floor = {sha-new, sha-recent}. sha-rollback is 4th by recency but its
        // digest is the release-authoritative previous-for-rollback → must survive; sha-junk dies.
        $report = $this->reclaimer->reclaim(self::REPO, new ImagePrunePolicy(keep: 2), [$rollbackDigest]);

        $argvs = $this->argvs();
        $this->assertContains(['docker', 'image', 'rm', 'sha-junk'], $argvs);
        $this->assertNotContains(['docker', 'image', 'rm', 'sha-rollback'], $argvs);
        $this->assertSame(1, $report->removed);
    }

    public function test_container_referenced_image_is_kept_even_when_beyond_recency_floor(): void
    {
        $this->runner->addResult($this->images([
            ['sha-new', 'sha256:' . str_repeat('a', 64)],
            ['sha-recent', 'sha256:' . str_repeat('b', 64)],
            ['sha-junk', 'sha256:' . str_repeat('c', 64)],
            ['sha-standby', 'sha256:' . str_repeat('d', 64)],
        ]));
        // A container still exists...
        $this->runner->addResult(new CommandResult(0, "container-1\n", '', 0.01));
        // ...and it references the standby image.
        $this->runner->addResult(new CommandResult(0, "sha-standby\n", '', 0.01));

        $report = $this->reclaimer->reclaim(self::REPO, new ImagePrunePolicy(keep: 2));

        $argvs = $this->argvs();
        $this->assertContains(['docker', 'ps', '-aq', '--no-trunc'], $argvs);
        $this->assertContains(['docker', 'inspect', '--format', '{{.Image}}', 'container-1'], $argvs);
        $this->assertContains(['docker', 'image', 'rm', 'sha-junk'], $argvs);
        $this->assertNotContains(['docker', 'image', 'rm', 'sha-standby'], $argvs);
        $this->assertSame(1, $report->removed);
    }

    public function test_in_use_image_removal_failure_does_not_throw(): void
    {
        $this->runner->addResult($this->images([
            ['sha-active', 'sha256:' . str_repeat('a', 64)],
            ['sha-prev', 'sha256:' . str_repeat('b', 64)],
            ['sha-old1', 'sha256:' . str_repeat('c', 64)],
        ]));
        $this->runner->addResult(new CommandResult(0, '', '', 0.01)); // no containers
        // rm of the superseded image "fails" (e.g. still referenced) — exit non-zero, no exception.
        $this->runner->addResult(new CommandResult(1, '', 'image is being used by running container', 0.01));

        $report = $this->reclaimer->reclaim(self::REPO, new ImagePrunePolicy(keep: 2));

        $this->assertSame(0, $report->removed);
        // Still proceeds to dangling + builder prune.
        $argvs = $this->argvs();
        $this->assertContains(['docker', 'image', 'prune', '-f'], $argvs);
    }

    public function test_nothing_to_remove_when_within_keep(): void
    {
        $this->runner->addResult($this->images([
            ['sha-active', 'sha256:' . str_repeat('a', 64)],
            ['sha-prev', 'sha256:' . str_repeat('b', 64)],
        ]));
        $this->runner->addResult(new CommandResult(0, '', '', 0.01)); // no containers

        $report = $this->reclaimer->reclaim(self::REPO, new ImagePrunePolicy(keep: 2));

        foreach ($this->argvs() as $argv) {
            $this->assertNotSame('rm', $argv[2] ?? null, 'nothing should be removed when within keep');
        }
        $this->assertSame(0, $report->removed);
    }

    public function test_docker_hub_repository_is_normalized_for_the_images_filter(): void
    {
        // Docker stores Docker Hub images WITHOUT the docker.io/ prefix, so the filter must be
        // normalized or it matches nothing (the real-world image leak on a Docker Hub deploy).
        $this->runner->addResult($this->images([['sha-only', 'sha256:' . str_repeat('a', 64)]]));
        $this->runner->addResult(new CommandResult(0, '', '', 0.01)); // no containers

        $this->reclaimer->reclaim('docker.io/sqoura/sqoura-backend', new ImagePrunePolicy(keep: 2));

        $this->assertSame(
            ['docker', 'images', 'sqoura/sqoura-backend', '--no-trunc', '--format', '{{.ID}}|{{.Digest}}'],
            $this->argvs()[0],
            'the docker.io/ prefix must be stripped for the images filter',
        );
    }

    public function test_official_image_library_namespace_is_stripped(): void
    {
        $this->runner->addResult($this->images([['sha-only', 'sha256:' . str_repeat('a', 64)]]));
        $this->runner->addResult(new CommandResult(0, '', '', 0.01));

        $this->reclaimer->reclaim('docker.io/library/redis', new ImagePrunePolicy(keep: 2));

        $this->assertSame('redis', $this->argvs()[0][2]);
    }

    public function test_non_dockerhub_registry_is_kept_verbatim(): void
    {
        $this->runner->addResult($this->images([['sha-only', 'sha256:' . str_repeat('a', 64)]]));
        $this->runner->addResult(new CommandResult(0, '', '', 0.01));

        $this->reclaimer->reclaim('ghcr.io/acme/app', new ImagePrunePolicy(keep: 2));

        $this->assertSame('ghcr.io/acme/app', $this->argvs()[0][2], 'other registries keep their prefix');
    }

    /**
     * @param list<array{0: string, 1: string}> $rows [imageId, registryDigest], newest-first
     */
    private function images(array $rows): CommandResult
    {
        $lines = array_map(static fn (array $r): string => $r[0] . '|' . $r[1], $rows);

        return new CommandResult(0, implode("\n", $lines) . "\n", '', 0.01);
    }

    /** @return list<list<string>> */
    private function argvs(): array
    {
        return array_map(static fn (array $c): array => $c['argv'], $this->runner->calls);
    }
}
