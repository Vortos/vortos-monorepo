<?php

declare(strict_types=1);

namespace Vortos\Deploy\Strategy;

final class DeployStrategyRegistry
{
    /** @var array<string, DeployStrategyInterface> */
    private array $strategies = [];

    public function register(DeployStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->key()->value] = $strategy;
    }

    public function get(DeployStrategy $key): DeployStrategyInterface
    {
        if (!isset($this->strategies[$key->value])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown deploy strategy "%s". Registered: [%s].',
                $key->value,
                implode(', ', array_keys($this->strategies)),
            ));
        }

        return $this->strategies[$key->value];
    }

    public function has(DeployStrategy $key): bool
    {
        return isset($this->strategies[$key->value]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->strategies);
        sort($keys);

        return $keys;
    }
}
