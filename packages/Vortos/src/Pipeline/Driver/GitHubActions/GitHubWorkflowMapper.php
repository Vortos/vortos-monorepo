<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Driver\GitHubActions;

use Vortos\Pipeline\Model\ActionStep;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Matrix;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Model\Pipeline;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\Trigger;
use Vortos\Pipeline\Model\TriggerEvent;

final class GitHubWorkflowMapper
{
    /** @return array<string, mixed> */
    public function map(Pipeline $pipeline): array
    {
        $workflow = [];
        $workflow['name'] = $pipeline->name;
        $workflow['on'] = $this->mapTriggers($pipeline->triggers);

        if (!$pipeline->permissions->isEmpty()) {
            $workflow['permissions'] = $pipeline->permissions->toArray();
        }

        if ($pipeline->concurrencyGroup !== null) {
            $workflow['concurrency'] = [
                'group' => $pipeline->concurrencyGroup,
                'cancel-in-progress' => $pipeline->concurrencyCancelInProgress,
            ];
        }

        $workflow['jobs'] = $this->mapJobs($pipeline->stages);

        return $workflow;
    }

    /**
     * @param list<Trigger> $triggers
     * @return array<string, mixed>
     */
    private function mapTriggers(array $triggers): array
    {
        $on = [];

        foreach ($triggers as $trigger) {
            $event = $trigger->event->value;
            $config = [];

            if ($trigger->branches !== []) {
                $config['branches'] = $trigger->branches;
            }

            if ($trigger->tags !== []) {
                $config['tags'] = $trigger->tags;
            }

            if ($trigger->paths !== []) {
                $config['paths'] = $trigger->paths;
            }

            if ($event === TriggerEvent::Push->value && $trigger->tags !== [] && $trigger->branches !== []) {
                $on[$event] = $config;
            } elseif ($config === []) {
                $on[$event] = null;
            } else {
                $on[$event] = $config;
            }
        }

        return $on;
    }

    /**
     * @param list<Stage> $stages
     * @return array<string, mixed>
     */
    private function mapJobs(array $stages): array
    {
        $jobs = [];

        foreach ($stages as $stage) {
            $jobs[$stage->id] = $this->mapJob($stage);
        }

        return $jobs;
    }

    /** @return array<string, mixed> */
    private function mapJob(Stage $stage): array
    {
        $job = [];
        $job['runs-on'] = $stage->runner->label;

        if ($stage->needs !== []) {
            $job['needs'] = $stage->needs;
        }

        if ($stage->condition !== null) {
            $job['if'] = $stage->condition;
        }

        if (!$stage->permissions->isEmpty()) {
            $job['permissions'] = $stage->permissions->toArray();
        }

        if ($stage->outputs !== []) {
            $job['outputs'] = $stage->outputs;
        }

        if ($stage->environment !== null) {
            $job['environment'] = $stage->environment;
        }

        if ($stage->timeoutMinutes !== null) {
            $job['timeout-minutes'] = $stage->timeoutMinutes;
        }

        if ($stage->matrix !== null) {
            $job['strategy'] = $this->mapStrategy($stage->matrix);
        }

        if ($stage->services !== []) {
            $job['services'] = $this->mapServices($stage->services);
        }

        if ($stage->env !== []) {
            $env = $stage->env;
            ksort($env);
            $job['env'] = $env;
        }

        $job['steps'] = $this->mapSteps($stage->steps);

        return $job;
    }

    /**
     * @param list<\Vortos\Pipeline\Model\ServiceContainer> $services
     * @return array<string, array<string, mixed>>
     */
    private function mapServices(array $services): array
    {
        $mapped = [];

        foreach ($services as $service) {
            $spec = ['image' => $service->image];

            if ($service->env !== []) {
                $spec['env'] = $service->env;
            }

            if ($service->ports !== []) {
                $spec['ports'] = $service->ports;
            }

            if ($service->options !== []) {
                $spec['options'] = implode(' ', $service->options);
            }

            $mapped[$service->name] = $spec;
        }

        return $mapped;
    }

    /** @return array<string, mixed> */
    private function mapStrategy(Matrix $matrix): array
    {
        $strategy = [
            'fail-fast' => $matrix->failFast,
            'matrix' => [
                $matrix->axisName => $matrix->values,
            ],
        ];

        return $strategy;
    }

    /**
     * @param list<CommandStep|ActionStep> $steps
     * @return list<array<string, mixed>>
     */
    private function mapSteps(array $steps): array
    {
        return array_map(
            fn (CommandStep|ActionStep $step): array => $this->mapStep($step),
            $steps,
        );
    }

    /** @return array<string, mixed> */
    private function mapStep(CommandStep|ActionStep $step): array
    {
        if ($step instanceof ActionStep) {
            return $this->mapActionStep($step);
        }

        return $this->mapCommandStep($step);
    }

    /** @return array<string, mixed> */
    private function mapActionStep(ActionStep $step): array
    {
        $mapped = [
            'name' => $step->name,
            'uses' => $step->action->toCommentedString(),
        ];

        if ($step->id !== null) {
            $mapped['id'] = $step->id;
        }

        if ($step->condition !== null) {
            $mapped['if'] = $step->condition;
        }

        if ($step->with !== []) {
            $mapped['with'] = $step->with;
        }

        if ($step->env !== []) {
            $mapped['env'] = $step->env;
        }

        return $mapped;
    }

    /** @return array<string, mixed> */
    private function mapCommandStep(CommandStep $step): array
    {
        $mapped = [
            'name' => $step->name,
            'run' => $step->run,
        ];

        if ($step->id !== null) {
            $mapped['id'] = $step->id;
        }

        if ($step->condition !== null) {
            $mapped['if'] = $step->condition;
        }

        if ($step->workingDirectory !== null) {
            $mapped['working-directory'] = $step->workingDirectory;
        }

        if ($step->env !== []) {
            $mapped['env'] = $step->env;
        }

        return $mapped;
    }
}
