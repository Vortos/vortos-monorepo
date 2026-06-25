<?php

declare(strict_types=1);

namespace Vortos\Observability\Slo;

use InvalidArgumentException;

/**
 * Validates a set of declared {@see Slo} resources at config time: unique names,
 * objective in range (enforced by {@see Slo} itself), and window coherence — fails
 * fast at config-validation time, never at runtime (Golden Rule discipline).
 */
final class SloRegistry
{
    /** @var array<string, Slo> */
    private array $slos = [];

    /**
     * @param list<Slo> $slos
     */
    public function __construct(array $slos = [])
    {
        foreach ($slos as $slo) {
            $this->add($slo);
        }
    }

    public function add(Slo $slo): void
    {
        if (isset($this->slos[$slo->name])) {
            throw new InvalidArgumentException(sprintf('Duplicate SLO name "%s".', $slo->name));
        }

        $this->slos[$slo->name] = $slo;
    }

    public function get(string $name): Slo
    {
        return $this->slos[$name] ?? throw new InvalidArgumentException(sprintf('Unknown SLO "%s".', $name));
    }

    public function has(string $name): bool
    {
        return isset($this->slos[$name]);
    }

    /** @return list<Slo> */
    public function all(): array
    {
        return array_values($this->slos);
    }
}
