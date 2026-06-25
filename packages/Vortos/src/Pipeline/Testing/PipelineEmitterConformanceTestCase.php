<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Testing;

use Vortos\OpsKit\Testing\ConformanceTestCase;
use Vortos\Pipeline\Emitter\PipelineEmitterInterface;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Model\Trigger;
use Vortos\Pipeline\Model\TriggerEvent;

abstract class PipelineEmitterConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createEmitter(): PipelineEmitterInterface;

    protected function createDriver(): PipelineEmitterInterface
    {
        return $this->createEmitter();
    }

    final public function test_emit_returns_non_empty_artifact_set(): void
    {
        $emitter = $this->createEmitter();
        $pipeline = $this->minimalPipeline();
        $artifacts = $emitter->emit($pipeline);

        $this->assertFalse($artifacts->isEmpty(), 'emit() must return at least one artifact.');
    }

    final public function test_every_artifact_path_is_relative_and_safe(): void
    {
        $emitter = $this->createEmitter();
        $pipeline = $this->minimalPipeline();
        $artifacts = $emitter->emit($pipeline);

        foreach ($artifacts as $artifact) {
            $this->assertStringNotContainsString('..', $artifact->relativePath, 'Artifact path must not contain "..".');
            $this->assertFalse(
                str_starts_with($artifact->relativePath, '/'),
                'Artifact path must be relative: ' . $artifact->relativePath,
            );
        }
    }

    final public function test_emit_is_deterministic(): void
    {
        $emitter = $this->createEmitter();
        $pipeline = $this->minimalPipeline();

        $first = $emitter->emit($pipeline);
        $second = $emitter->emit($pipeline);

        $this->assertCount(\count($first), $second);

        foreach ($first as $i => $artifact) {
            $this->assertSame(
                $artifact->contents,
                $second->artifacts[$i]->contents,
                'Re-emitting identical input must yield identical bytes (deterministic).',
            );
        }
    }

    final public function test_artifacts_have_non_empty_contents(): void
    {
        $emitter = $this->createEmitter();
        $pipeline = $this->minimalPipeline();
        $artifacts = $emitter->emit($pipeline);

        foreach ($artifacts as $artifact) {
            $this->assertNotSame('', $artifact->contents, 'Artifact contents must be non-empty.');
        }
    }

    private function minimalPipeline(): Pipeline
    {
        return new Pipeline(
            name: 'Test CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'tests',
                    displayName: 'Tests',
                    kind: StageKind::Test,
                    steps: [new CommandStep('Run tests', 'composer test')],
                    permissions: Permissions::readOnly(),
                ),
            ],
            permissions: Permissions::readOnly(),
        );
    }
}
