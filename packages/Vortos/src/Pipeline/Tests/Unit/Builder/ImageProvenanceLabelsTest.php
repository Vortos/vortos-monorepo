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
use Vortos\Pipeline\Registry\CiRegistryLoginProviderRegistry;

/**
 * A built image must say which commit produced it.
 *
 * Without an explicit label the image is not unlabelled — it INHERITS
 * org.opencontainers.image.revision from its base image, so it advertises a commit from an
 * unrelated repository. That is worse than saying nothing: it answers "what source built this"
 * confidently and wrongly, and the SHA does not even resolve in the repo anyone would check.
 * Production was found running an image whose revision label was a SHA absent from the app
 * repository entirely; the deployed contents had to be verified by reading files inside the
 * container instead.
 *
 * The stakes are set by what the pipeline already does elsewhere: these images are cosign-signed
 * and carry SBOM and provenance attestations. A signature proves the bytes are unmodified, not
 * that they came from the source you believe — so a provenance label that misidentifies its own
 * commit quietly undercuts the audit trail the signing exists to establish.
 */
final class ImageProvenanceLabelsTest extends TestCase
{
    /** @return array<string, string> the `with:` inputs of the build-and-push step */
    private function buildStepInputs(): array
    {
        $definition = new PipelineDefinition(
            imageRepository: 'ghcr.io/acme/app',
            nativeRunnerLabel: 'ubuntu-24.04-arm',
        );

        $registry = new CiRegistryLoginProviderRegistry(new ServiceLocator([
            'ghcr' => static fn () => new GhcrCiLoginProvider(),
        ]));

        $pipeline = (new PipelineBuilder(new StageGate(), $registry))->build($definition);

        foreach ($pipeline->stages as $stage) {
            foreach ($stage->steps as $step) {
                if ($step instanceof ActionStep && $step->name === 'Build and push') {
                    return $step->with;
                }
            }
        }

        self::fail('no build-push-action step found in the generated pipeline');
    }

    public function test_the_build_stamps_the_commit_that_produced_the_image(): void
    {
        $labels = $this->buildStepInputs()['labels'] ?? '';

        self::assertStringContainsString(
            'org.opencontainers.image.revision=${{ github.sha }}',
            $labels,
            'the image would inherit a revision label from its base image and name the wrong commit',
        );
    }

    public function test_the_build_stamps_the_repository_the_commit_lives_in(): void
    {
        $labels = $this->buildStepInputs()['labels'] ?? '';

        self::assertStringContainsString(
            'org.opencontainers.image.source=',
            $labels,
            'a revision is only resolvable if the image also says which repository it belongs to',
        );
    }

    /**
     * Labels are newline-separated by build-push-action. Joining them any other way silently
     * produces one malformed label rather than four good ones.
     */
    public function test_labels_are_newline_separated(): void
    {
        $labels = (string) ($this->buildStepInputs()['labels'] ?? '');

        self::assertGreaterThanOrEqual(
            2,
            substr_count($labels, "\n"),
            'labels must be newline-separated for build-push-action to parse them individually',
        );
    }
}
