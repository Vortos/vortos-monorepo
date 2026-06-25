<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Exception\InvalidPipelineException;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Model\Trigger;
use Vortos\Pipeline\Model\TriggerEvent;

final class PipelineTest extends TestCase
{
    public function test_valid_pipeline_construction(): void
    {
        $pipeline = $this->minimal();

        $this->assertSame('Test CI', $pipeline->name);
        $this->assertCount(1, $pipeline->stages);
        $this->assertSame(['tests'], $pipeline->stageIds());
    }

    public function test_empty_stages_rejected(): void
    {
        $this->expectException(InvalidPipelineException::class);
        $this->expectExceptionMessage('at least one stage');

        new Pipeline(
            name: 'Empty',
            triggers: [],
            stages: [],
        );
    }

    public function test_duplicate_stage_ids_rejected(): void
    {
        $this->expectException(InvalidPipelineException::class);
        $this->expectExceptionMessage('Duplicate stage ID');

        new Pipeline(
            name: 'Duplicate',
            triggers: [],
            stages: [
                $this->stage('tests', StageKind::Test),
                $this->stage('tests', StageKind::StaticAnalysis),
            ],
        );
    }

    public function test_unknown_need_rejected(): void
    {
        $this->expectException(InvalidPipelineException::class);
        $this->expectExceptionMessage('no stage with that ID');

        new Pipeline(
            name: 'Unknown Need',
            triggers: [],
            stages: [
                new Stage(
                    id: 'deploy',
                    displayName: 'Deploy',
                    kind: StageKind::Deploy,
                    steps: [new CommandStep('deploy', 'vortos deploy')],
                    needs: ['nonexistent'],
                ),
            ],
        );
    }

    public function test_cyclic_needs_rejected(): void
    {
        $this->expectException(InvalidPipelineException::class);
        $this->expectExceptionMessage('Cyclic');

        new Pipeline(
            name: 'Cyclic',
            triggers: [],
            stages: [
                new Stage(
                    id: 'a',
                    displayName: 'A',
                    kind: StageKind::Test,
                    steps: [new CommandStep('test', 'phpunit')],
                    needs: ['b'],
                ),
                new Stage(
                    id: 'b',
                    displayName: 'B',
                    kind: StageKind::StaticAnalysis,
                    steps: [new CommandStep('analyse', 'phpstan')],
                    needs: ['a'],
                ),
            ],
        );
    }

    public function test_valid_needs_accepted(): void
    {
        $pipeline = new Pipeline(
            name: 'With Needs',
            triggers: [],
            stages: [
                $this->stage('tests', StageKind::Test),
                new Stage(
                    id: 'deploy',
                    displayName: 'Deploy',
                    kind: StageKind::Deploy,
                    steps: [new CommandStep('deploy', 'vortos deploy')],
                    needs: ['tests'],
                ),
            ],
        );

        $this->assertCount(2, $pipeline->stages);
    }

    public function test_stage_by_id(): void
    {
        $pipeline = $this->minimal();

        $this->assertNotNull($pipeline->stageById('tests'));
        $this->assertNull($pipeline->stageById('nonexistent'));
    }

    public function test_to_array(): void
    {
        $pipeline = new Pipeline(
            name: 'Test CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [$this->stage('tests', StageKind::Test)],
            permissions: Permissions::readOnly(),
            concurrencyGroup: 'ci-${{ github.ref }}',
            concurrencyCancelInProgress: true,
        );

        $array = $pipeline->toArray();

        $this->assertSame('Test CI', $array['name']);
        $this->assertCount(1, $array['triggers']);
        $this->assertCount(1, $array['stages']);
        $this->assertArrayHasKey('permissions', $array);
        $this->assertArrayHasKey('concurrency', $array);
        $this->assertSame('ci-${{ github.ref }}', $array['concurrency']['group']);
        $this->assertTrue($array['concurrency']['cancel_in_progress']);
    }

    public function test_empty_name_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Pipeline(name: '', triggers: [], stages: [$this->stage('tests', StageKind::Test)]);
    }

    private function minimal(): Pipeline
    {
        return new Pipeline(
            name: 'Test CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [$this->stage('tests', StageKind::Test)],
        );
    }

    private function stage(string $id, StageKind $kind): Stage
    {
        return new Stage(
            id: $id,
            displayName: ucfirst($id),
            kind: $kind,
            steps: [new CommandStep('Run', 'echo test')],
        );
    }
}
