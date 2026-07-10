<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Reclaim;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinitionBuilder;
use Vortos\Deploy\Driver\Docker\ImageReclaimer;
use Vortos\Deploy\Reclaim\Schedule\ReclaimImagesCommand;
use Vortos\Deploy\Reclaim\Schedule\ReclaimImagesHandler;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeManifestReadModel;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

final class ReclaimImagesHandlerTest extends TestCase
{
    private const REPO = 'docker.io/acme/app';

    public function test_reclaims_repository_from_ledger_protecting_release_digests(): void
    {
        $currentDigest = 'sha256:' . str_repeat('a', 64);
        $previousDigest = 'sha256:' . str_repeat('b', 64);

        $manifests = new FakeManifestReadModel(
            latest: $this->manifest('build-2', $currentDigest),
            previous: $this->manifest('build-1', $previousDigest),
        );

        $releaseStore = new FakeDeployStateStore();
        $releaseStore->recordCurrentRelease(new CurrentRelease(
            env: 'production',
            activeColor: ActiveColor::Blue,
            imageDigest: $currentDigest,
            buildId: 'build-2',
            planHash: 'hash',
            recordedAt: new \DateTimeImmutable(),
            generation: 1,
        ));

        $runner = new FakeCommandRunner();
        // docker images: current, previous, and an orphan to be reclaimed.
        $runner->addResult(new \Vortos\Deploy\Execution\CommandResult(
            0,
            "id-current|$currentDigest\nid-previous|$previousDigest\nid-orphan|sha256:" . str_repeat('c', 64) . "\n",
            '',
            0.01,
        ));
        $runner->addResult(new \Vortos\Deploy\Execution\CommandResult(0, '', '', 0.01)); // no containers

        $handler = new ReclaimImagesHandler(
            reclaimer: new ImageReclaimer($runner),
            definitionBuilder: new DeploymentDefinitionBuilder(),
            releaseStore: $releaseStore,
            manifests: $manifests,
        );

        $handler(new ReclaimImagesCommand('production'));

        $argvs = array_map(static fn (array $c): array => $c['argv'], $runner->calls);

        // It reclaimed OUR repository (resolved from the ledger), normalized for the docker.io/ prefix.
        $this->assertContains(
            ['docker', 'images', 'acme/app', '--no-trunc', '--format', '{{.ID}}|{{.Digest}}'],
            $argvs,
        );
        // The orphan (keep=2 default → current+previous are the recency floor AND protected) is removed…
        $this->assertContains(['docker', 'image', 'rm', 'id-orphan'], $argvs);
        // …while the release-authoritative current + previous digests are never removed.
        $this->assertNotContains(['docker', 'image', 'rm', 'id-current'], $argvs);
        $this->assertNotContains(['docker', 'image', 'rm', 'id-previous'], $argvs);
    }

    public function test_noop_when_no_manifest_for_environment(): void
    {
        $runner = new FakeCommandRunner();

        $handler = new ReclaimImagesHandler(
            reclaimer: new ImageReclaimer($runner),
            definitionBuilder: new DeploymentDefinitionBuilder(),
            releaseStore: new FakeDeployStateStore(),
            manifests: new FakeManifestReadModel(), // no latest
        );

        $handler(new ReclaimImagesCommand('production'));

        $this->assertCount(0, $runner->calls, 'nothing has ever deployed → no reclaim');
    }

    private function manifest(string $buildId, string $digest): BuildManifest
    {
        return new BuildManifest(
            $buildId,
            'abc1234',
            self::REPO,
            $digest,
            Arch::Arm64,
            'production',
            SchemaFingerprint::empty(),
            new \DateTimeImmutable(),
        );
    }
}
