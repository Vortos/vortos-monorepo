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
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;

/**
 * Splitting the serving image from the deploy-ops image.
 *
 * One image used to do both jobs, so the internet-facing container permanently carried the docker
 * CLI and compose plugin that only the cutover one-shots ever invoke — and every Go stdlib CVE
 * published against those binaries failed the scan gate for the *serving* image, which is supposed
 * to describe the request-serving attack surface. These tests pin the separation.
 */
final class PipelineBuilderOpsImageTest extends TestCase
{
    private const REPO = 'docker.io/acme/app';

    public function test_without_an_ops_target_nothing_changes(): void
    {
        $build = $this->stage($this->pipeline($this->definition()), StageKind::Build);

        self::assertNull($this->actionStep($build, 'Build and push deploy-ops image'));
        self::assertSame(['image' => '${{ steps.image.outputs.digest }}'], $build->outputs);
    }

    public function test_the_serving_build_pins_its_target_when_the_split_is_on(): void
    {
        $build = $this->stage($this->pipeline($this->definition(serve: 'app', ops: 'ops')), StageKind::Build);
        $serving = $this->actionStep($build, 'Build and push');

        self::assertNotNull($serving);
        // Without this the serving build would take the Dockerfile's LAST stage — which, once an
        // ops stage exists, is the ops stage. The split would silently undo itself.
        self::assertSame('app', $serving->with['target'] ?? null);
    }

    public function test_the_ops_image_is_a_sibling_tag_in_the_same_repository(): void
    {
        $build = $this->stage($this->pipeline($this->definition(serve: 'app', ops: 'ops')), StageKind::Build);
        $ops = $this->actionStep($build, 'Build and push deploy-ops image');

        self::assertNotNull($ops);
        self::assertSame('ops', $ops->with['target'] ?? null);
        // Same repo, so no second Docker Hub repository, credential or retention policy.
        self::assertSame(self::REPO . ':sha-${{ github.sha }}-ops', $ops->with['tags'] ?? null);
    }

    public function test_both_images_are_scanned_but_only_ops_carries_an_ignore_file(): void
    {
        $build = $this->stage(
            $this->pipeline($this->definition(serve: 'app', ops: 'ops', scan: true, opsIgnore: '.trivyignore.ops')),
            StageKind::Build,
        );

        $serving = $this->actionStep($build, 'Scan image for vulnerabilities (CVE gate)');
        $ops     = $this->actionStep($build, 'Scan deploy-ops image for vulnerabilities (CVE gate)');

        self::assertNotNull($serving);
        self::assertNotNull($ops);
        // The whole point: once the toolchain is out of the serving image, a finding there is real,
        // so it gets no waiver list at all.
        self::assertArrayNotHasKey('trivyignores', $serving->with);
        self::assertSame('.trivyignore.ops', $ops->with['trivyignores'] ?? null);
        self::assertSame('1', $serving->with['exit-code'] ?? null, 'the serving gate still fails closed');
        self::assertSame('1', $ops->with['exit-code'] ?? null, 'so does the ops gate');
    }

    public function test_the_ops_image_is_signed_and_verified_like_the_serving_one(): void
    {
        $build = $this->stage($this->pipeline($this->definition(serve: 'app', ops: 'ops', sign: true)), StageKind::Build);

        // It runs migrations and the cutover against production data — an unverified ops image
        // would be a hole straight through the supply chain the serving image is protected by.
        self::assertNotNull($this->commandStep($build, 'Sign deploy-ops image (keyless, Sigstore)'));
        self::assertNotNull($this->commandStep($build, 'Verify deploy-ops image signature'));
    }

    public function test_one_shots_run_from_ops_while_the_cutover_still_pins_the_serving_digest(): void
    {
        $deploy = $this->stage($this->pipeline($this->definition(serve: 'app', ops: 'ops')), StageKind::Deploy);
        $script = $this->deployScript($deploy);

        self::assertStringContainsString('@${{ needs.build.outputs.opsimage }} php bin/console', $script);
        // The digest written into the compose — what actually answers requests — must stay the
        // serving image, or the split would put the deploy toolchain back in front of traffic.
        self::assertStringContainsString('--image-digest=${{ needs.build.outputs.image }}', $script);
        self::assertStringContainsString('docker pull ' . self::REPO . '@${{ needs.build.outputs.image }}', $script);
        self::assertStringContainsString('docker pull ' . self::REPO . '@${{ needs.build.outputs.opsimage }}', $script);
    }

    public function test_the_build_stage_exposes_the_ops_digest(): void
    {
        $build = $this->stage($this->pipeline($this->definition(serve: 'app', ops: 'ops')), StageKind::Build);

        self::assertSame('${{ steps.opsimage.outputs.opsdigest }}', $build->outputs['opsimage'] ?? null);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function definition(
        ?string $serve = null,
        ?string $ops = null,
        bool $scan = false,
        bool $sign = false,
        ?string $opsIgnore = null,
    ): PipelineDefinition {
        return new PipelineDefinition(
            imageRepository: self::REPO,
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            serveTarget: $serve,
            opsTarget: $ops,
            opsScanIgnoreFile: $opsIgnore,
            emitScanGate: $scan,
            emitSign: $sign,
        );
    }

    private function pipeline(PipelineDefinition $definition): Pipeline
    {
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

    private function deployScript(Stage $stage): string
    {
        foreach ($stage->steps as $step) {
            if ($step instanceof CommandStep && $step->name === 'Deploy on target over SSH') {
                return $step->run;
            }
        }

        self::fail('No deploy-over-SSH step was emitted.');
    }
}
