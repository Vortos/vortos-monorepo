<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;

final class StageOutputsTest extends TestCase
{
    public function test_outputs_rendered_when_non_empty(): void
    {
        $stage = new Stage(
            id: 'build',
            displayName: 'Build',
            kind: StageKind::Build,
            steps: [new CommandStep('build', 'docker build')],
            outputs: ['image' => '${{ steps.image.outputs.digest }}'],
        );

        $array = $stage->toArray();
        $this->assertArrayHasKey('outputs', $array);
        $this->assertSame(['image' => '${{ steps.image.outputs.digest }}'], $array['outputs']);
    }

    public function test_outputs_omitted_when_empty(): void
    {
        $stage = new Stage(
            id: 'tests',
            displayName: 'Tests',
            kind: StageKind::Test,
            steps: [new CommandStep('run', 'phpunit')],
        );

        $array = $stage->toArray();
        $this->assertArrayNotHasKey('outputs', $array);
    }

    public function test_bad_output_key_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stage output key must match');

        new Stage(
            id: 'build',
            displayName: 'Build',
            kind: StageKind::Build,
            steps: [new CommandStep('build', 'docker build')],
            outputs: ['BAD KEY' => 'value'],
        );
    }

    public function test_output_key_starting_with_number_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Stage(
            id: 'build',
            displayName: 'Build',
            kind: StageKind::Build,
            steps: [new CommandStep('build', 'docker build')],
            outputs: ['1invalid' => 'value'],
        );
    }

    public function test_valid_output_keys(): void
    {
        $stage = new Stage(
            id: 'build',
            displayName: 'Build',
            kind: StageKind::Build,
            steps: [new CommandStep('build', 'docker build')],
            outputs: [
                'image' => 'val1',
                'image-digest' => 'val2',
                'some_output' => 'val3',
            ],
        );

        $this->assertCount(3, $stage->outputs);
    }
}
