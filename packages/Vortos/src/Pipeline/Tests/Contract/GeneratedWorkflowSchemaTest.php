<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Builder\KnownActionFactory;
use Vortos\Pipeline\Builder\PipelineBuilder;
use Vortos\Pipeline\Builder\StageGate;
use Vortos\Pipeline\Definition\PipelineDefinition;
use Vortos\Pipeline\Driver\GitHubActions\GitHubActionsEmitter;
use Vortos\Pipeline\Driver\GitHubActions\GitHubWorkflowMapper;
use Vortos\Pipeline\Driver\GitHubActions\SplitWorkflowGenerator;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Emitter\EmittedArtifact;
use Vortos\Pipeline\Emitter\EmittedArtifactSet;
use Vortos\Pipeline\Model\SplitPackage;

final class GeneratedWorkflowSchemaTest extends TestCase
{
    private EmittedArtifactSet $artifacts;

    protected function setUp(): void
    {
        $definition = new PipelineDefinition();

        $gate = new StageGate();
        $builder = new PipelineBuilder($gate);

        $splitPackages = [
            new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain'),
            new SplitPackage('packages/Vortos/src/Foundation', 'vortos-foundation'),
        ];

        $pipeline = $builder->build($definition, $splitPackages);

        $emitter = new GitHubActionsEmitter(
            new GitHubWorkflowMapper(),
            new SplitWorkflowGenerator(),
            new WorkflowYamlWriter(),
            $definition,
        );

        $this->artifacts = $emitter->emit($pipeline);
    }

    public function test_ci_workflow_has_required_top_level_keys(): void
    {
        $ci = $this->artifact('.github/workflows/ci.yml');
        $this->assertNotNull($ci);

        $this->assertYamlContainsKey($ci->contents, 'name');
        $this->assertYamlContainsTopLevelKey($ci->contents, "'on'");
        $this->assertYamlContainsKey($ci->contents, 'jobs');
    }

    public function test_split_workflow_has_required_top_level_keys(): void
    {
        $split = $this->artifact('.github/workflows/split.yml');
        $this->assertNotNull($split);

        $this->assertYamlContainsKey($split->contents, 'name');
        $this->assertYamlContainsTopLevelKey($split->contents, "'on'");
        $this->assertYamlContainsKey($split->contents, 'jobs');
    }

    public function test_ci_workflow_has_valid_trigger_events(): void
    {
        $ci = $this->artifact('.github/workflows/ci.yml');
        $this->assertNotNull($ci);

        $this->assertStringContainsString('push', $ci->contents);
        $this->assertStringContainsString('pull_request', $ci->contents);
    }

    public function test_every_job_has_runs_on(): void
    {
        foreach ($this->artifacts as $artifact) {
            preg_match_all('/^  \w+:$/m', $artifact->contents, $jobMatches);

            $this->assertStringContainsString(
                'runs-on:',
                $artifact->contents,
                'Every job must have a runs-on key in ' . $artifact->relativePath,
            );
        }
    }

    public function test_every_job_has_steps(): void
    {
        foreach ($this->artifacts as $artifact) {
            $this->assertStringContainsString(
                'steps:',
                $artifact->contents,
                'Every job must have a steps key in ' . $artifact->relativePath,
            );
        }
    }

    public function test_every_step_has_uses_or_run(): void
    {
        foreach ($this->artifacts as $artifact) {
            $lines = explode("\n", $artifact->contents);
            $inSteps = false;

            foreach ($lines as $line) {
                if (str_contains($line, 'steps:')) {
                    $inSteps = true;
                    continue;
                }

                if ($inSteps && preg_match('/^\s{6}- name:/', $line)) {
                    $nextLines = $this->linesAfter($lines, $line);
                    $hasUsesOrRun = false;
                    foreach ($nextLines as $next) {
                        if (preg_match('/^\s+(uses|run):/', $next)) {
                            $hasUsesOrRun = true;
                            break;
                        }
                        if (preg_match('/^\s{6}- name:/', $next)) {
                            break;
                        }
                    }
                    $this->assertTrue(
                        $hasUsesOrRun,
                        'Step in ' . $artifact->relativePath . ' must have uses: or run:',
                    );
                }
            }
        }
    }

    public function test_all_uses_references_are_sha_pinned(): void
    {
        foreach ($this->artifacts as $artifact) {
            preg_match_all('/uses:\s+(.+)$/m', $artifact->contents, $matches);

            foreach ($matches[1] as $ref) {
                $ref = trim($ref, "'\" ");
                $this->assertMatchesRegularExpression(
                    '/@[0-9a-f]{40}/',
                    $ref,
                    'uses: reference must be SHA-pinned in ' . $artifact->relativePath . ': ' . $ref,
                );
            }
        }
    }

    public function test_ci_workflow_has_permissions_block(): void
    {
        $ci = $this->artifact('.github/workflows/ci.yml');
        $this->assertNotNull($ci);

        $this->assertStringContainsString('permissions:', $ci->contents);
    }

    public function test_split_workflow_has_permissions_block(): void
    {
        $split = $this->artifact('.github/workflows/split.yml');
        $this->assertNotNull($split);

        $this->assertStringContainsString('permissions:', $split->contents);
    }

    public function test_ci_workflow_has_concurrency_block(): void
    {
        $ci = $this->artifact('.github/workflows/ci.yml');
        $this->assertNotNull($ci);

        $this->assertStringContainsString('concurrency:', $ci->contents);
        $this->assertStringContainsString('cancel-in-progress:', $ci->contents);
    }

    public function test_ci_workflow_has_timeout_minutes(): void
    {
        $ci = $this->artifact('.github/workflows/ci.yml');
        $this->assertNotNull($ci);

        $this->assertStringContainsString('timeout-minutes:', $ci->contents);
    }

    public function test_deploy_job_calls_vortos_deploy(): void
    {
        $ci = $this->artifact('.github/workflows/ci.yml');
        $this->assertNotNull($ci);

        $this->assertStringContainsString('deploy:doctor', $ci->contents);
        $this->assertStringContainsString('deploy --env=', $ci->contents);
        $this->assertStringContainsString('--yes', $ci->contents);
        $this->assertStringContainsString('--json', $ci->contents);
    }

    public function test_deploy_job_has_environment_matrix(): void
    {
        $ci = $this->artifact('.github/workflows/ci.yml');
        $this->assertNotNull($ci);

        $this->assertStringContainsString('matrix:', $ci->contents);
        $this->assertStringContainsString('environment:', $ci->contents);
    }

    public function test_split_workflow_has_matrix_strategy(): void
    {
        $split = $this->artifact('.github/workflows/split.yml');
        $this->assertNotNull($split);

        $this->assertStringContainsString('strategy:', $split->contents);
        $this->assertStringContainsString('matrix:', $split->contents);
        $this->assertStringContainsString('fail-fast: false', $split->contents);
    }

    public function test_permissions_contain_valid_scope_names(): void
    {
        $validScopes = ['contents', 'packages', 'id-token', 'actions', 'pull-requests', 'checks'];

        foreach ($this->artifacts as $artifact) {
            preg_match_all('/^\s{2}(\w[\w-]*):\s+(read|write|none)\s*$/m', $artifact->contents, $matches);

            foreach ($matches[1] as $scope) {
                if (in_array($scope, ['group', 'cancel-in-progress', 'fail-fast'], true)) {
                    continue;
                }
                $this->assertContains(
                    $scope,
                    $validScopes,
                    'Invalid permission scope "' . $scope . '" in ' . $artifact->relativePath,
                );
            }
        }
    }

    private function artifact(string $path): ?EmittedArtifact
    {
        return $this->artifacts->byPath($path);
    }

    private function assertYamlContainsKey(string $yaml, string $key): void
    {
        $this->assertMatchesRegularExpression(
            '/^' . preg_quote($key, '/') . ':/m',
            $yaml,
            'YAML must contain top-level key: ' . $key,
        );
    }

    private function assertYamlContainsTopLevelKey(string $yaml, string $key): void
    {
        $this->assertMatchesRegularExpression(
            '/^' . preg_quote($key, '/') . ':/m',
            $yaml,
            'YAML must contain top-level key: ' . $key,
        );
    }

    /**
     * @param list<string> $allLines
     * @return list<string>
     */
    private function linesAfter(array $allLines, string $targetLine): array
    {
        $found = false;
        $result = [];
        foreach ($allLines as $line) {
            if ($found) {
                $result[] = $line;
                if (\count($result) > 10) {
                    break;
                }
            }
            if ($line === $targetLine && !$found) {
                $found = true;
            }
        }

        return $result;
    }
}
