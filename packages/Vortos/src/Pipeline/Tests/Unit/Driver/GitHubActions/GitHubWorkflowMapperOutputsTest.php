<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Driver\GitHubActions;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Permission;
use Vortos\Pipeline\Model\PermissionAccess;
use Vortos\Pipeline\Model\PermissionScope;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Model\PinnedAction;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Model\Trigger;
use Vortos\Pipeline\Model\TriggerEvent;

final class GitHubWorkflowMapperOutputsTest extends TestCase
{
    private function makePipeline(Stage ...$stages): Pipeline
    {
        return new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: array_values($stages),
        );
    }

    public function test_job_outputs_rendered(): void
    {
        $stage = new Stage(
            id: 'build',
            displayName: 'Build',
            kind: StageKind::Build,
            steps: [new CommandStep('echo', 'echo digest >> $GITHUB_OUTPUT', id: 'image')],
            outputs: ['image' => '${{ steps.image.outputs.digest }}'],
        );

        $mapper = new GitHubWorkflowMapper();
        $result = $mapper->map($this->makePipeline($stage));

        $this->assertArrayHasKey('outputs', $result['jobs']['build']);
        $this->assertSame(
            ['image' => '${{ steps.image.outputs.digest }}'],
            $result['jobs']['build']['outputs'],
        );
    }

    public function test_job_outputs_omitted_when_empty(): void
    {
        $stage = new Stage(
            id: 'tests',
            displayName: 'Tests',
            kind: StageKind::Test,
            steps: [new CommandStep('run', 'phpunit')],
        );

        $mapper = new GitHubWorkflowMapper();
        $result = $mapper->map($this->makePipeline($stage));

        $this->assertArrayNotHasKey('outputs', $result['jobs']['tests']);
    }

    public function test_step_id_rendered(): void
    {
        $stage = new Stage(
            id: 'build',
            displayName: 'Build',
            kind: StageKind::Build,
            steps: [new CommandStep('expose', 'echo', id: 'image')],
        );

        $mapper = new GitHubWorkflowMapper();
        $result = $mapper->map($this->makePipeline($stage));

        $step = $result['jobs']['build']['steps'][0];
        $this->assertArrayHasKey('id', $step);
        $this->assertSame('image', $step['id']);
    }

    public function test_step_id_omitted_when_null(): void
    {
        $stage = new Stage(
            id: 'tests',
            displayName: 'Tests',
            kind: StageKind::Test,
            steps: [new CommandStep('run', 'phpunit')],
        );

        $mapper = new GitHubWorkflowMapper();
        $result = $mapper->map($this->makePipeline($stage));

        $step = $result['jobs']['tests']['steps'][0];
        $this->assertArrayNotHasKey('id', $step);
    }

    public function test_step_env_rendered(): void
    {
        $stage = new Stage(
            id: 'build',
            displayName: 'Build',
            kind: StageKind::Build,
            steps: [new CommandStep('build', 'docker build', env: ['DOCKER_BUILDKIT' => '1'])],
        );

        $mapper = new GitHubWorkflowMapper();
        $result = $mapper->map($this->makePipeline($stage));

        $step = $result['jobs']['build']['steps'][0];
        $this->assertArrayHasKey('env', $step);
        $this->assertSame(['DOCKER_BUILDKIT' => '1'], $step['env']);
    }

    public function test_step_env_omitted_when_empty(): void
    {
        $stage = new Stage(
            id: 'tests',
            displayName: 'Tests',
            kind: StageKind::Test,
            steps: [new CommandStep('run', 'phpunit')],
        );

        $mapper = new GitHubWorkflowMapper();
        $result = $mapper->map($this->makePipeline($stage));

        $step = $result['jobs']['tests']['steps'][0];
        $this->assertArrayNotHasKey('env', $step);
    }

    public function test_action_step_id_rendered(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        $stage = new Stage(
            id: 'build',
            displayName: 'Build',
            kind: StageKind::Build,
            steps: [new ActionStep('Checkout', $action, id: 'checkout')],
        );

        $mapper = new GitHubWorkflowMapper();
        $result = $mapper->map($this->makePipeline($stage));

        $step = $result['jobs']['build']['steps'][0];
        $this->assertArrayHasKey('id', $step);
        $this->assertSame('checkout', $step['id']);
    }

    public function test_action_step_env_rendered(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
        $stage = new Stage(
            id: 'build',
            displayName: 'Build',
            kind: StageKind::Build,
            steps: [new ActionStep('Checkout', $action, env: ['GH_TOKEN' => '${{ secrets.TOKEN }}'])],
        );

        $mapper = new GitHubWorkflowMapper();
        $result = $mapper->map($this->makePipeline($stage));

        $step = $result['jobs']['build']['steps'][0];
        $this->assertArrayHasKey('env', $step);
    }
}
