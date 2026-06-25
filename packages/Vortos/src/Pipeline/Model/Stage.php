<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final readonly class Stage
{
    /**
     * @param list<CommandStep|ActionStep> $steps
     * @param list<string>                 $needs
     * @param array<string, string>        $outputs
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public StageKind $kind,
        public array $steps,
        public array $needs = [],
        public ?string $condition = null,
        public RunnerSpec $runner = new RunnerSpec(),
        public Permissions $permissions = new Permissions(),
        public ?string $environment = null,
        public ?int $timeoutMinutes = null,
        public ?Matrix $matrix = null,
        public array $outputs = [],
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Stage ID must be non-empty.');
        }

        if ($steps === [] && $matrix === null) {
            throw new \InvalidArgumentException(sprintf('Stage "%s" must contain at least one step.', $id));
        }

        foreach (array_keys($this->outputs) as $key) {
            if (preg_match('/^[a-z][a-z0-9_-]*$/', (string) $key) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Stage output key must match [a-z][a-z0-9_-]*, got "%s".',
                    $key,
                ));
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'kind' => $this->kind->value,
            'steps' => array_map(
                static fn (CommandStep|ActionStep $s): array => $s->toArray(),
                $this->steps,
            ),
        ];

        if ($this->needs !== []) {
            $data['needs'] = $this->needs;
        }

        if ($this->condition !== null) {
            $data['condition'] = $this->condition;
        }

        $data['runner'] = $this->runner->toArray();

        if (!$this->permissions->isEmpty()) {
            $data['permissions'] = $this->permissions->toArray();
        }

        if ($this->environment !== null) {
            $data['environment'] = $this->environment;
        }

        if ($this->timeoutMinutes !== null) {
            $data['timeout_minutes'] = $this->timeoutMinutes;
        }

        if ($this->matrix !== null) {
            $data['matrix'] = $this->matrix->toArray();
        }

        if ($this->outputs !== []) {
            $data['outputs'] = $this->outputs;
        }

        return $data;
    }
}
