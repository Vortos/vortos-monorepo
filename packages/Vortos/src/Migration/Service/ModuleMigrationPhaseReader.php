<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Attribute\DeployPhase;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;

final class ModuleMigrationPhaseReader implements MigrationPhaseReaderInterface
{
    /** @var array<string, MigrationPhase>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ModuleMigrationRegistryInterface $registry,
    ) {}

    public function phaseOf(string $migrationId): MigrationPhase
    {
        $map = $this->resolveAll();

        if (!isset($map[$migrationId])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown migration ID "%s": not found in the module migration registry. '
                . 'Cannot resolve deploy phase for an unregistered migration.',
                $migrationId,
            ));
        }

        return $map[$migrationId];
    }

    /**
     * @param list<string> $ids
     * @return array<string, MigrationPhase>
     */
    public function phasesFor(array $ids): array
    {
        $map = $this->resolveAll();
        $result = [];

        foreach ($ids as $id) {
            if (!isset($map[$id])) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown migration ID "%s": not found in the module migration registry.',
                    $id,
                ));
            }

            $result[$id] = $map[$id];
        }

        return $result;
    }

    /** @return array<string, MigrationPhase> */
    private function resolveAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];
        $descriptors = $this->registry->descriptorsByClass();

        foreach ($descriptors as $class => $descriptor) {
            $this->cache[$class] = $this->resolvePhaseForClass($class);
        }

        return $this->cache;
    }

    private function resolvePhaseForClass(string $class): MigrationPhase
    {
        if (!class_exists($class)) {
            return MigrationPhase::safeDefault();
        }

        $reflection = new \ReflectionClass($class);

        $attributes = $reflection->getAttributes(DeployPhase::class);

        if ($attributes === []) {
            return MigrationPhase::safeDefault();
        }

        /** @var DeployPhase $attr */
        $attr = $attributes[0]->newInstance();

        return $attr->phase;
    }
}
