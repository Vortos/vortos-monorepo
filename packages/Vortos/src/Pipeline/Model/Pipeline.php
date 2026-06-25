<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

use Vortos\Pipeline\Exception\InvalidPipelineException;

final readonly class Pipeline
{
    /**
     * @param list<Trigger> $triggers
     * @param list<Stage>   $stages
     */
    public function __construct(
        public string $name,
        public array $triggers,
        public array $stages,
        public Permissions $permissions = new Permissions(),
        public ?string $concurrencyGroup = null,
        public bool $concurrencyCancelInProgress = true,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Pipeline name must be non-empty.');
        }

        if ($stages === []) {
            throw InvalidPipelineException::emptyStages();
        }

        $this->validateStageIds();
        $this->validateNeeds();
        $this->detectCycles();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'triggers' => array_map(
                static fn (Trigger $t): array => $t->toArray(),
                $this->triggers,
            ),
            'stages' => array_map(
                static fn (Stage $s): array => $s->toArray(),
                $this->stages,
            ),
        ];

        if (!$this->permissions->isEmpty()) {
            $data['permissions'] = $this->permissions->toArray();
        }

        if ($this->concurrencyGroup !== null) {
            $data['concurrency'] = [
                'group' => $this->concurrencyGroup,
                'cancel_in_progress' => $this->concurrencyCancelInProgress,
            ];
        }

        return $data;
    }

    /** @return list<string> */
    public function stageIds(): array
    {
        return array_map(static fn (Stage $s): string => $s->id, $this->stages);
    }

    public function stageById(string $id): ?Stage
    {
        foreach ($this->stages as $stage) {
            if ($stage->id === $id) {
                return $stage;
            }
        }

        return null;
    }

    private function validateStageIds(): void
    {
        $seen = [];
        foreach ($this->stages as $stage) {
            if (isset($seen[$stage->id])) {
                throw InvalidPipelineException::duplicateStageId($stage->id);
            }
            $seen[$stage->id] = true;
        }
    }

    private function validateNeeds(): void
    {
        $ids = array_flip($this->stageIds());

        foreach ($this->stages as $stage) {
            foreach ($stage->needs as $need) {
                if (!isset($ids[$need])) {
                    throw InvalidPipelineException::unknownNeed($stage->id, $need);
                }
            }
        }
    }

    private function detectCycles(): void
    {
        $graph = [];
        foreach ($this->stages as $stage) {
            $graph[$stage->id] = $stage->needs;
        }

        $visited = [];
        $stack = [];

        foreach (array_keys($graph) as $node) {
            if (!isset($visited[$node])) {
                $this->dfs($node, $graph, $visited, $stack);
            }
        }
    }

    /**
     * @param array<string, list<string>> $graph
     * @param array<string, bool>         $visited
     * @param array<string, bool>         $stack
     */
    private function dfs(string $node, array $graph, array &$visited, array &$stack): void
    {
        $visited[$node] = true;
        $stack[$node] = true;

        foreach ($graph[$node] ?? [] as $neighbor) {
            if (!isset($visited[$neighbor])) {
                $this->dfs($neighbor, $graph, $visited, $stack);
            } elseif (isset($stack[$neighbor])) {
                throw InvalidPipelineException::cyclicNeeds([$neighbor, $node]);
            }
        }

        unset($stack[$node]);
    }
}
