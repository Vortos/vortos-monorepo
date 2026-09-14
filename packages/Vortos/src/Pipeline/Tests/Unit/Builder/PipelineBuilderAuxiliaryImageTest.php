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
use Vortos\Pipeline\Model\AuxiliaryImage;
use Vortos\Pipeline\Model\AuxiliaryImageName;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\ComposeServiceSet;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\ProjectPath;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;

/**
 * RC-9: an auxiliary image is built from the release, gated, signed, verified before release, run by
 * digest and proven live — the backup sidecar was the one image with none of that.
 */
final class PipelineBuilderAuxiliaryImageTest extends TestCase
{
    private const REPO = 'docker.io/acme/app';

    public function test_it_is_a_sibling_tag_built_from_the_exact_serving_digest(): void
    {
        $build = $this->stage(StageKind::Build);
        $step = $this->actionStep($build, 'Build and push backup image');

        self::assertNotNull($step);
        self::assertSame(self::REPO . ':sha-${{ github.sha }}-backup', $step->with['tags'] ?? null);
        self::assertSame('docker/backup/Dockerfile', $step->with['file'] ?? null);
        self::assertSame('IMAGE=' . self::REPO . '@${{ steps.build.outputs.digest }}', $step->with['build-args'] ?? null);
        self::assertArrayNotHasKey('target', $step->with, 'the serving target must not leak into another Dockerfile');
        self::assertSame('${{ steps.auxbackup.outputs.digest }}', $build->outputs['auxbackup'] ?? null);
        self::assertSame('${{ steps.image.outputs.digest }}', $build->outputs['image'] ?? null);
    }

    public function test_it_gets_every_gate_the_serving_image_gets(): void
    {
        $build = $this->stage(StageKind::Build, ignore: 'docker/backup/.trivyignore');

        $scan = $this->actionStep($build, 'Scan backup image for vulnerabilities (CVE gate)');
        self::assertNotNull($scan);
        self::assertSame('1', $scan->with['exit-code'] ?? null);
        self::assertSame(self::REPO . '@${{ steps.buildauxbackup.outputs.digest }}', $scan->with['image-ref'] ?? null);
        self::assertSame('docker/backup/.trivyignore', $scan->with['trivyignores'] ?? null);
        self::assertNotNull($this->actionStep($build, 'Generate backup image SBOM'));
        self::assertNotNull($this->commandStep($build, 'Sign backup image (keyless, Sigstore)'));
        self::assertStringContainsString('cosign verify', (string) $this->commandStep($build, 'Verify backup image signature')?->run);
    }

    public function test_no_ignore_file_is_emitted_unless_declared(): void
    {
        $scan = $this->actionStep($this->stage(StageKind::Build), 'Scan backup image for vulnerabilities (CVE gate)');

        self::assertNotNull($scan);
        self::assertArrayNotHasKey('trivyignores', $scan->with);
    }

    public function test_the_deploy_verifies_its_signature_before_release(): void
    {
        $verify = $this->commandStep($this->stage(StageKind::Deploy), 'Verify the backup image signature before releasing it');

        self::assertNotNull($verify);
        self::assertStringEndsWith(self::REPO . '@${{ needs.build.outputs.auxbackup }}', $verify->run);
    }

    public function test_the_deploy_pins_it_before_the_sync_and_proves_it_live_after_the_cutover(): void
    {
        $script = $this->deployScript($this->stage(StageKind::Deploy));
        $ref = self::REPO . '@${{ needs.build.outputs.auxbackup }}';

        $pull = strpos($script, 'docker pull ' . $ref);
        $env = strpos($script, "'VORTOS_IMAGE_BACKUP=" . $ref . "'");
        $sync = strpos($script, 'vortos:deploy:compose:sync');
        $cutover = strpos($script, 'deploy --env=');
        $converge = strpos($script, 'compose -f /opt/vortos/docker-compose.prod.yaml up -d --no-deps backup-scheduler');
        $proof = strpos($script, '--filter label=com.docker.compose.service=backup-scheduler');
        foreach ([$pull, $env, $sync, $cutover, $converge, $proof] as $position) {
            self::assertIsInt($position);
        }

        self::assertTrue($pull < $env && $env < $sync, 'pulled and pinned before the sync, whose validator resolves the reference');
        self::assertTrue($cutover < $converge && $converge < $proof, 'converged after the cutover, then proven');
        self::assertStringContainsString('mv -f /opt/vortos/.env.incoming /opt/vortos/.env', $script);
        self::assertStringContainsString('::error title=Auxiliary image not live::', $script);
        self::assertStringContainsString('healthy|no-healthcheck-running', $script);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function stage(StageKind $kind, ?string $ignore = null): Stage
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
            verifySignatureBeforeRelease: true,
            auxiliaryImages: [new AuxiliaryImage(
                new AuxiliaryImageName('backup'),
                new ProjectPath('docker/backup/Dockerfile'),
                'IMAGE',
                new ComposeServiceSet(['backup-scheduler']),
                $ignore,
            )],
        );

        $registry = new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
            'docker-hub' => static fn () => new DockerHubCiLoginProvider(),
            'gcp-artifact-registry' => static fn () => new GcpArtifactRegistryCiLoginProvider(),
        ]));
        $pipeline = (new PipelineBuilder(new StageGate(), $registry))->build($definition);

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

    private function deployScript(Stage $stage): string
    {
        return (string) $this->commandStep($stage, 'Deploy on target over SSH')?->run;
    }
}
