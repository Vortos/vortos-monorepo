<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\Registry\DockerHubCiLoginProvider;
use Vortos\Pipeline\Driver\Registry\GcpArtifactRegistryCiLoginProvider;
use Vortos\Pipeline\Driver\Registry\GhcrCiLoginProvider;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\AuxiliaryImageName;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\PinnedImage;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\ProjectPath;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;

/**
 * RC-5: a pinned image is built on demand with every gate the serving image gets, never deployed by the build,
 * and every application-repository digest the topology runs is signature-verified before release.
 */
final class PipelineBuilderPinnedImageTest extends TestCase
{
    private const REPO = 'docker.io/acme/app';

    public function test_it_is_built_on_demand_into_the_application_repository_with_every_gate(): void
    {
        $stage = $this->stage($this->pipeline(), StageKind::PinnedImage);

        self::assertSame('pinned-postgres', $stage->id);
        $build = $this->actionStep($stage, 'Build and push');
        self::assertNotNull($build);
        self::assertSame(self::REPO . ':pinned-postgres-${{ github.sha }}', $build->with['tags'] ?? null);
        self::assertSame('docker/postgres', $build->with['context'] ?? null);
        self::assertSame('docker/postgres/Dockerfile', $build->with['file'] ?? null);
        self::assertSame('type=gha,scope=pinned-postgres', $build->with['cache-from'] ?? null);
        self::assertNotNull($this->commandStep($stage, 'Verify architecture'));
        self::assertNotNull($this->actionStep($stage, 'Generate SBOM'));
        $scan = $this->actionStep($stage, 'Scan image for vulnerabilities (CVE gate)');
        self::assertSame('1', $scan?->with['exit-code'] ?? null);
        self::assertSame('docker/postgres/.trivyignore', $scan?->with['trivyignores'] ?? null);
        self::assertStringContainsString('cosign sign --yes ' . self::REPO . '@${{ steps.build.outputs.digest }}', (string) $this->commandStep($stage, 'Sign image (keyless, Sigstore)')?->run);
        self::assertStringContainsString(
            'cosign verify --certificate-identity-regexp "^${{ github.server_url }}/${{ github.repository }}/\.github/workflows/images\.yml@refs/"',
            (string) $this->commandStep($stage, 'Verify image signature')?->run,
        );
        self::assertStringContainsString('GITHUB_STEP_SUMMARY', (string) $this->commandStep($stage, 'Report the digest to commit')?->run);
    }

    public function test_the_build_never_deploys(): void
    {
        $stage = $this->stage($this->pipeline(), StageKind::PinnedImage);

        foreach ($stage->steps as $step) {
            if ($step instanceof CommandStep) {
                self::assertStringNotContainsString('ssh ', $step->run);
                self::assertStringNotContainsString('compose', $step->run);
            }
        }
        self::assertNull($stage->environment);
    }

    public function test_the_deploy_verifies_every_application_repository_digest_the_topology_runs(): void
    {
        $verify = $this->commandStep($this->stage($this->pipeline(), StageKind::Deploy), 'Verify every pinned image the topology runs before releasing it');

        self::assertNotNull($verify);
        self::assertStringContainsString("grep -oE 'docker\\.io/acme/app@sha256:[a-f0-9]{64}' docker-compose.prod.yaml", $verify->run);
        self::assertStringContainsString('exit 1', $verify->run, 'finding no digest while pinned images are declared must fail, not verify nothing');
        self::assertStringContainsString(
            'cosign verify --certificate-identity-regexp "^${{ github.server_url }}/${{ github.repository }}/\.github/workflows/images\.yml@refs/"',
            $verify->run,
            'a datastore digest is vouched for only by the workflow that built it with the pinned-image gates',
        );
    }

    public function test_nothing_is_emitted_without_pinned_images(): void
    {
        $pipeline = $this->pipeline(pinned: false);

        foreach ($pipeline->stages as $stage) {
            self::assertNotSame(StageKind::PinnedImage, $stage->kind);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function pipeline(bool $pinned = true): Pipeline
    {
        $definition = new PipelineDefinition(
            imageRepository: self::REPO,
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            oidc: false,
            registryProvider: 'docker-hub',
            syncComposeTopology: true,
            syncComposeTopologyApply: true,
            emitScanGate: true,
            emitSign: true,
            buildCache: true,
            verifySignatureBeforeRelease: true,
            pinnedImages: $pinned ? [new PinnedImage(
                new AuxiliaryImageName('postgres'),
                new ProjectPath('docker/postgres/Dockerfile'),
                new ProjectPath('docker/postgres'),
                new ComposeServiceSet(['write_db']),
                'docker/postgres/.trivyignore',
            )] : [],
        );

        $registry = new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
            'docker-hub' => static fn () => new DockerHubCiLoginProvider(),
            'gcp-artifact-registry' => static fn () => new GcpArtifactRegistryCiLoginProvider(),
        ]));

        return (new PipelineBuilder(new StageGate(), $registry))->build($definition);
    }

    private function stage(Pipeline $pipeline, StageKind $kind): Stage
    {
        foreach ($pipeline->stages as $stage) {
            if ($stage->kind === $kind) {
                return $stage;
            }
        }

        self::fail(sprintf('No %s stage was emitted.', $kind->name));
    }

    private function actionStep(Stage $stage, string $name): ?ActionStep
    {
        foreach ($stage->steps as $step) {
            if ($step instanceof ActionStep && $step->name === $name) {
                return $step;
            }
        }

        return null;
    }

    private function commandStep(Stage $stage, string $name): ?CommandStep
    {
        foreach ($stage->steps as $step) {
            if ($step instanceof CommandStep && $step->name === $name) {
                return $step;
            }
        }

        return null;
    }
}
