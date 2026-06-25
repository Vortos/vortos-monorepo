<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final readonly class ActionStep
{
    /**
     * @param array<string, string> $with
     * @param array<string, string> $env
     */
    public function __construct(
        public string $name,
        public PinnedAction $action,
        public array $with = [],
        public ?string $condition = null,
        public ?string $id = null,
        public array $env = [],
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Step name must be non-empty.');
        }

        if ($id !== null && preg_match('/^[a-z][a-z0-9_-]*$/', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Step id must match [a-z][a-z0-9_-]*, got "%s".',
                $id,
            ));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'type' => 'action',
            'name' => $this->name,
            'action' => $this->action->toArray(),
        ];

        if ($this->with !== []) {
            $with = $this->with;
            ksort($with);
            $data['with'] = $with;
        }

        if ($this->condition !== null) {
            $data['condition'] = $this->condition;
        }

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }

        if ($this->env !== []) {
            $env = $this->env;
            ksort($env);
            $data['env'] = $env;
        }

        return $data;
    }
}
