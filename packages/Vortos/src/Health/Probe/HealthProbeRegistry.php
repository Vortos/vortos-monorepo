<?php

declare(strict_types=1);

namespace Vortos\Health\Probe;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

final class HealthProbeRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('health', $drivers);
    }

    public function probe(string $key): HealthProbeInterface
    {
        /** @var HealthProbeInterface */
        return $this->get($key);
    }

    /** @return list<HealthProbeInterface> */
    public function allProbes(): array
    {
        $probes = [];
        foreach ($this->keys() as $key) {
            $probes[] = $this->probe($key);
        }

        return $probes;
    }

    /** @return list<HealthProbeInterface> */
    public function probesOfKind(ProbeKind $kind): array
    {
        return array_values(array_filter(
            $this->allProbes(),
            static fn (HealthProbeInterface $p): bool => $p->kind() === $kind,
        ));
    }
}
