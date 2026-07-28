<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\Registry\GhcrCiLoginProvider;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;

/**
 * Things apps had to hand-patch into the generated workflow must be expressible in the model.
 *
 * A generated file that cannot express what its consumers need does not stop them needing it — it
 * makes them edit the output, and then `pipeline:generate --force` deletes their edit silently.
 * One app's config carried a written warning listing exactly which blocks to paste back in after
 * every regeneration, and `pipeline:verify` reported drift permanently as a result. That is the
 * generator failing at its job: drift detection is worthless once drift is the expected state.
 *
 * Two blocks were being lost. The layer cache is worth ~9 minutes a build. The release-time
 * signature verification is a security control. Losing either is silent — the pipeline stays green
 * while it gets slower, or stops re-checking the signature of the artifact it is about to release.
 */
final class GeneratedPipelinePreservesHandEditsTest extends TestCase
{
    private function pipeline(bool $buildCache, bool $verifyBeforeRelease): Pipeline
    {
        $definition = new PipelineDefinition(
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            emitSign: true,
            buildCache: $buildCache,
            verifySignatureBeforeRelease: $verifyBeforeRelease,
        );

        $registry = new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
        ]));

        return (new PipelineBuilder(new StageGate(), $registry))->build($definition);
    }

    /** @return array<string, string> the build step's `with:` inputs */
    private function buildInputs(Pipeline $pipeline): array
    {
        foreach ($pipeline->stages as $stage) {
            foreach ($stage->steps as $step) {
                if ($step instanceof ActionStep && $step->name === 'Build and push') {
                    return $step->with;
                }
            }
        }

        self::fail('no build step found');
    }

    /** @return list<string> every step name in the deploy stage */
    private function deployStepNames(Pipeline $pipeline): array
    {
        foreach ($pipeline->stages as $stage) {
            if ($stage->id !== 'deploy') {
                continue;
            }

            return array_map(static fn ($s): string => $s->name, $stage->steps);
        }

        self::fail('no deploy stage found');
    }

    public function test_the_layer_cache_is_expressible(): void
    {
        $with = $this->buildInputs($this->pipeline(true, false));

        self::assertSame('type=gha', $with['cache-from'] ?? null);
        self::assertSame('type=gha,mode=max', $with['cache-to'] ?? null);
    }

    public function test_the_layer_cache_stays_off_unless_asked_for(): void
    {
        $with = $this->buildInputs($this->pipeline(false, false));

        self::assertArrayNotHasKey('cache-from', $with);
        self::assertArrayNotHasKey('cache-to', $with);
    }

    public function test_release_time_signature_verification_is_expressible(): void
    {
        $names = $this->deployStepNames($this->pipeline(false, true));

        self::assertContains('Install Cosign', $names);
        self::assertContains('Verify the image signature before releasing it', $names);
    }

    /**
     * cosign reads the signature as a tag from the same repository. On a private one that needs a
     * login ON THE RUNNER — the deploy's own registry login happens on the target and does nothing
     * for a cosign running here, so without this the verify dies with UNAUTHORIZED.
     */
    public function test_the_release_verification_authenticates_to_the_registry_first(): void
    {
        $names = $this->deployStepNames($this->pipeline(false, true));

        $loginAt = null;
        $verifyAt = null;
        foreach ($names as $i => $name) {
            if ($loginAt === null && str_contains(strtolower($name), 'login')) {
                $loginAt = $i;
            }
            if ($name === 'Verify the image signature before releasing it') {
                $verifyAt = $i;
            }
        }

        self::assertNotNull($loginAt, 'no registry login in the deploy stage: cosign would hit UNAUTHORIZED');
        self::assertNotNull($verifyAt);
        self::assertLessThan($verifyAt, $loginAt, 'the registry login must precede the signature verification');
    }

    /** Verification must land before the deploy actually runs, or it is not a gate. */
    public function test_verification_runs_before_the_deploy_step(): void
    {
        $names = $this->deployStepNames($this->pipeline(false, true));

        $verifyAt = array_search('Verify the image signature before releasing it', $names, true);
        $deployAt = array_search('Deploy on target over SSH', $names, true);

        self::assertNotFalse($verifyAt);
        self::assertNotFalse($deployAt);
        self::assertLessThan($deployAt, $verifyAt);
    }

    public function test_no_release_verification_without_signing(): void
    {
        // Asking to verify a signature that is never produced would fail every deploy closed.
        $definition = new PipelineDefinition(
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
            emitSign: false,
            verifySignatureBeforeRelease: true,
        );

        $registry = new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
        ]));

        $pipeline = (new PipelineBuilder(new StageGate(), $registry))->build($definition);

        self::assertNotContains(
            'Verify the image signature before releasing it',
            $this->deployStepNames($pipeline),
        );
    }

    public function test_deploy_stays_unchanged_when_verification_is_off(): void
    {
        $names = $this->deployStepNames($this->pipeline(false, false));

        self::assertNotContains('Verify the image signature before releasing it', $names);
        self::assertContains('Deploy on target over SSH', $names);
    }
}
