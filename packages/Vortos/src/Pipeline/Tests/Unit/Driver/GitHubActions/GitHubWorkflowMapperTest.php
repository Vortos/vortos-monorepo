<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Driver\GitHubActions;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\KnownActionFactory;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Matrix;
use Vortos\Pipeline\Model\Permission;
use Vortos\Pipeline\Model\PermissionAccess;
use Vortos\Pipeline\Model\PermissionScope;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\RunnerSpec;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;
use Vortos\Pipeline\Model\Trigger;
use Vortos\Pipeline\Model\TriggerEvent;

final class GitHubWorkflowMapperTest extends TestCase
{
    private GitHubWorkflowMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new GitHubWorkflowMapper();
    }

    public function test_maps_pipeline_name(): void
    {
        $result = $this->mapper->map($this->minimalPipeline('My CI'));
        $this->assertSame('My CI', $result['name']);
    }

    public function test_maps_push_trigger_with_branches_and_tags(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'], tags: ['*'])],
            stages: [$this->minimalStage()],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);

        $this->assertArrayHasKey('push', $result['on']);
        $this->assertSame(['main'], $result['on']['push']['branches']);
        $this->assertSame(['*'], $result['on']['push']['tags']);
    }

    public function test_maps_pull_request_trigger(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::PullRequest)],
            stages: [$this->minimalStage()],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $this->assertArrayHasKey('pull_request', $result['on']);
    }

    public function test_maps_workflow_level_permissions(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [$this->minimalStage()],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $this->assertSame(['contents' => 'read'], $result['permissions']);
    }

    public function test_maps_concurrency_group(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [$this->minimalStage()],
            permissions: Permissions::readOnly(),
            concurrencyGroup: 'ci-${{ github.ref }}',
            concurrencyCancelInProgress: true,
        );

        $result = $this->mapper->map($pipeline);
        $this->assertSame('ci-${{ github.ref }}', $result['concurrency']['group']);
        $this->assertTrue($result['concurrency']['cancel-in-progress']);
    }

    public function test_maps_stages_to_jobs(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                $this->minimalStage('tests'),
                new Stage(
                    id: 'analyse',
                    displayName: 'Analyse',
                    kind: StageKind::StaticAnalysis,
                    steps: [new CommandStep('Run', 'composer analyse')],
                    needs: ['tests'],
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $this->assertArrayHasKey('tests', $result['jobs']);
        $this->assertArrayHasKey('analyse', $result['jobs']);
    }

    public function test_maps_stage_needs(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                $this->minimalStage('tests'),
                new Stage(
                    id: 'deploy',
                    displayName: 'Deploy',
                    kind: StageKind::Deploy,
                    steps: [new CommandStep('Deploy', 'deploy')],
                    needs: ['tests'],
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $this->assertSame(['tests'], $result['jobs']['deploy']['needs']);
    }

    public function test_maps_stage_condition(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'deploy',
                    displayName: 'Deploy',
                    kind: StageKind::Deploy,
                    steps: [new CommandStep('Deploy', 'deploy')],
                    condition: "github.ref == 'refs/heads/main'",
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $this->assertSame("github.ref == 'refs/heads/main'", $result['jobs']['deploy']['if']);
    }

    public function test_maps_stage_permissions(): void
    {
        $perms = new Permissions([
            new Permission(PermissionScope::Contents, PermissionAccess::Write),
        ]);

        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'deploy',
                    displayName: 'Deploy',
                    kind: StageKind::Deploy,
                    steps: [new CommandStep('Deploy', 'deploy')],
                    permissions: $perms,
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $this->assertSame(['contents' => 'write'], $result['jobs']['deploy']['permissions']);
    }

    public function test_maps_stage_environment(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'deploy',
                    displayName: 'Deploy',
                    kind: StageKind::Deploy,
                    steps: [new CommandStep('Deploy', 'deploy')],
                    environment: 'production',
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $this->assertSame('production', $result['jobs']['deploy']['environment']);
    }

    public function test_maps_stage_timeout(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'tests',
                    displayName: 'Tests',
                    kind: StageKind::Test,
                    steps: [new CommandStep('Test', 'phpunit')],
                    timeoutMinutes: 15,
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $this->assertSame(15, $result['jobs']['tests']['timeout-minutes']);
    }

    public function test_maps_stage_matrix(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'deploy',
                    displayName: 'Deploy',
                    kind: StageKind::Deploy,
                    steps: [new CommandStep('Deploy', 'deploy')],
                    matrix: new Matrix(
                        axisName: 'env',
                        values: [['env' => 'staging'], ['env' => 'prod']],
                        failFast: false,
                    ),
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $strategy = $result['jobs']['deploy']['strategy'];
        $this->assertFalse($strategy['fail-fast']);
        $this->assertCount(2, $strategy['matrix']['env']);
    }

    public function test_maps_command_step_to_run(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'tests',
                    displayName: 'Tests',
                    kind: StageKind::Test,
                    steps: [new CommandStep('Run tests', './vendor/bin/phpunit')],
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $step = $result['jobs']['tests']['steps'][0];
        $this->assertSame('Run tests', $step['name']);
        $this->assertSame('./vendor/bin/phpunit', $step['run']);
        $this->assertArrayNotHasKey('uses', $step);
    }

    public function test_maps_action_step_to_uses(): void
    {
        $action = KnownActionFactory::checkout();

        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'tests',
                    displayName: 'Tests',
                    kind: StageKind::Test,
                    steps: [new ActionStep('Checkout', $action)],
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $step = $result['jobs']['tests']['steps'][0];
        $this->assertSame('Checkout', $step['name']);
        $this->assertSame($action->toCommentedString(), $step['uses']);
        $this->assertArrayNotHasKey('run', $step);
    }

    public function test_maps_action_step_with_params(): void
    {
        $action = KnownActionFactory::setupPhp();

        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'tests',
                    displayName: 'Tests',
                    kind: StageKind::Test,
                    steps: [new ActionStep('Setup PHP', $action, ['php-version' => '8.5'])],
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $step = $result['jobs']['tests']['steps'][0];
        $this->assertSame(['php-version' => '8.5'], $step['with']);
    }

    public function test_maps_command_step_with_working_directory(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'build',
                    displayName: 'Build',
                    kind: StageKind::Build,
                    steps: [new CommandStep('npm install', 'npm install', workingDirectory: 'packages/admin')],
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $step = $result['jobs']['build']['steps'][0];
        $this->assertSame('packages/admin', $step['working-directory']);
    }

    public function test_maps_step_condition(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [
                new Stage(
                    id: 'deploy',
                    displayName: 'Deploy',
                    kind: StageKind::Deploy,
                    steps: [new CommandStep('Deploy', 'deploy', condition: "github.ref == 'refs/heads/main'")],
                ),
            ],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);
        $step = $result['jobs']['deploy']['steps'][0];
        $this->assertSame("github.ref == 'refs/heads/main'", $step['if']);
    }

    public function test_no_concurrency_when_null(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [$this->minimalStage()],
            permissions: Permissions::readOnly(),
            concurrencyGroup: null,
        );

        $result = $this->mapper->map($pipeline);
        $this->assertArrayNotHasKey('concurrency', $result);
    }

    private function minimalPipeline(string $name = 'CI'): Pipeline
    {
        return new Pipeline(
            name: $name,
            triggers: [new Trigger(TriggerEvent::Push, branches: ['main'])],
            stages: [$this->minimalStage()],
            permissions: Permissions::readOnly(),
        );
    }

    private function minimalStage(string $id = 'tests'): Stage
    {
        return new Stage(
            id: $id,
            displayName: 'Tests',
            kind: StageKind::Test,
            steps: [new CommandStep('Run tests', 'composer test')],
        );
    }

    public function test_maps_job_level_env(): void
    {
        $pipeline = new Pipeline(
            name: 'CI',
            triggers: [new Trigger(TriggerEvent::Push)],
            stages: [new Stage(
                id: 'deploy',
                displayName: 'Deploy',
                kind: StageKind::Deploy,
                steps: [new CommandStep('Deploy', 'vortos deploy')],
                env: ['VORTOS_DEPLOY_USER' => '${{ vars.VORTOS_DEPLOY_USER }}', 'VORTOS_DEPLOY_HOST' => '${{ vars.VORTOS_DEPLOY_HOST }}'],
            )],
            permissions: Permissions::readOnly(),
        );

        $result = $this->mapper->map($pipeline);

        $this->assertSame(
            ['VORTOS_DEPLOY_HOST' => '${{ vars.VORTOS_DEPLOY_HOST }}', 'VORTOS_DEPLOY_USER' => '${{ vars.VORTOS_DEPLOY_USER }}'],
            $result['jobs']['deploy']['env'],
        );
    }

    public function test_omits_job_env_when_empty(): void
    {
        $result = $this->mapper->map($this->minimalPipeline());

        $this->assertArrayNotHasKey('env', $result['jobs']['tests']);
    }
}
