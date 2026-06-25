<?php

declare(strict_types=1);

namespace Vortos\Docker\Worker;

final class WorkerProcessRegistry
{
    /** @var array<string, WorkerProcessDefinition> */
    private array $definitions = [];

    /** @param iterable<WorkerProcessDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->add($definition);
        }
    }

    public function add(WorkerProcessDefinition $definition): void
    {
        if (isset($this->definitions[$definition->name])) {
            throw new \InvalidArgumentException(sprintf('Duplicate worker definition "%s".', $definition->name));
        }

        $this->definitions[$definition->name] = $definition;
        ksort($this->definitions);
    }

    /** @return WorkerProcessDefinition[] */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @param string[] $names */
    public function selected(array $names): self
    {
        if ($names === []) {
            return $this;
        }

        $selected = new self();
        foreach ($names as $name) {
            $selected->add($this->get($name));
        }

        return $selected;
    }

    public function get(string $name): WorkerProcessDefinition
    {
        return $this->definitions[$name] ?? throw new \InvalidArgumentException(sprintf(
            'Unknown worker "%s". Available workers: %s',
            $name,
            implode(', ', array_keys($this->definitions)),
        ));
    }

    public function maxDrainDeadline(): int
    {
        $max = 0;
        foreach ($this->definitions as $def) {
            if ($def->drainDeadline > $max) {
                $max = $def->drainDeadline;
            }
        }

        return $max;
    }

    public function isEmpty(): bool
    {
        return $this->definitions === [];
    }
}
