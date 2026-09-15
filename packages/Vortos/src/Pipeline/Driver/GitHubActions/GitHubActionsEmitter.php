<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Driver\GitHubActions;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Emitter\Capability\EmitterCapability;
use Vortos\Pipeline\Emitter\EmittedArtifact;
use Vortos\Pipeline\Emitter\EmittedArtifactSet;
use Vortos\Pipeline\Emitter\PipelineEmitterInterface;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\SplitPackage;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Model\Trigger;
use Vortos\Pipeline\Model\TriggerEvent;

#[AsDriver('github')]
final class GitHubActionsEmitter implements PipelineEmitterInterface
{
    public function __construct(
        private readonly GitHubWorkflowMapper $mapper,
        private readonly SplitWorkflowGenerator $splitGenerator,
        private readonly WorkflowYamlWriter $yamlWriter,
        private readonly PipelineDefinition $definition,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            EmitterCapability::GithubActions->value => true,
            EmitterCapability::GitlabCi->value => false,
            EmitterCapability::Matrix->value => true,
            EmitterCapability::Oidc->value => $this->definition->oidc,
            EmitterCapability::ShaPinning->value => true,
            EmitterCapability::ReusableWorkflows->value => false,
            EmitterCapability::BuildNativeArch->value => $this->definition->hasBuildStage(),
        ]);
    }

    public function emit(Pipeline $pipeline): EmittedArtifactSet
    {
        $artifacts = [];

        $ciStages = array_values(array_filter(
            $pipeline->stages,
            static fn (Stage $s): bool => $s->kind !== StageKind::Split && $s->kind !== StageKind::PinnedImage,
        ));

        // RC-5: pinned images build on demand in their own workflow — on a push that changes their Dockerfile
        // or build context, or when dispatched — never as part of a release, so a datastore image only ever
        // gets new bytes through a digest someone commits. The push trigger is deliberately not limited to the
        // deployment branch: the first digest must exist before the release that runs it (see PinnedImage).
        $pinnedStages = array_values(array_filter(
            $pipeline->stages,
            static fn (Stage $s): bool => $s->kind === StageKind::PinnedImage,
        ));

        if ($pinnedStages !== []) {
            $paths = [];
            foreach ($this->definition->pinnedImages as $pinned) {
                $paths[] = $pinned->dockerfile->value;
                $paths[] = $pinned->context->value . '/**';
            }

            $imagesPipeline = new Pipeline(
                name: 'Pinned Images',
                triggers: [
                    new Trigger(TriggerEvent::WorkflowDispatch),
                    new Trigger(TriggerEvent::Push, paths: array_values(array_unique($paths))),
                ],
                stages: $pinnedStages,
                permissions: $pipeline->permissions,
                concurrencyGroup: '${{ github.workflow }}-${{ github.ref }}',
                concurrencyCancelInProgress: false,
            );

            $artifacts[] = new EmittedArtifact(
                relativePath: \Vortos\Pipeline\Model\PinnedImage::WORKFLOW_PATH,
                contents: $this->yamlWriter->dump($this->mapper->map($imagesPipeline)),
                description: 'Pinned images — built, scanned and signed on demand; digests are committed to the topology',
            );
        }

        if ($ciStages !== []) {
            $ciPipeline = new Pipeline(
                name: $this->definition->workflowName ?? $pipeline->name,
                triggers: $pipeline->triggers,
                stages: $ciStages,
                permissions: $pipeline->permissions,
                concurrencyGroup: $pipeline->concurrencyGroup,
                concurrencyCancelInProgress: $pipeline->concurrencyCancelInProgress,
            );

            $workflowArray = $this->mapper->map($ciPipeline);
            $yaml = $this->yamlWriter->dump($workflowArray);

            $artifacts[] = new EmittedArtifact(
                relativePath: '.github/workflows/' . $this->definition->workflowFilename,
                contents: $yaml,
                description: 'CI/CD pipeline — test, analyse, deploy',
            );
        }

        $splitStage = null;
        foreach ($pipeline->stages as $stage) {
            if ($stage->kind === StageKind::Split) {
                $splitStage = $stage;
                break;
            }
        }

        if ($splitStage !== null && $splitStage->matrix !== null) {
            $splitPackages = array_map(
                static fn (array $v): SplitPackage => new SplitPackage(
                    $v['local_path'],
                    $v['split_repository'],
                ),
                $splitStage->matrix->values,
            );

            $splitArray = $this->splitGenerator->generate($splitPackages, $this->definition);
            $splitYaml = $this->yamlWriter->dump($splitArray);

            $artifacts[] = new EmittedArtifact(
                relativePath: '.github/workflows/split.yml',
                contents: $splitYaml,
                description: 'Monorepo subtree-split workflow',
            );
        }

        return new EmittedArtifactSet($artifacts);
    }
}
