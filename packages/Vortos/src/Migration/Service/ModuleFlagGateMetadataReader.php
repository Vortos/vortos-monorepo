<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Attribute\GatedByFlag;
use Vortos\Migration\Schema\FlagGateMetadataReaderInterface;
use Vortos\Migration\Schema\FlagGateSpec;

final class ModuleFlagGateMetadataReader implements FlagGateMetadataReaderInterface
{
    /** @var array<string, ?FlagGateSpec>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ModuleMigrationRegistryInterface $registry,
    ) {}

    public function flagGateFor(string $migrationId): ?FlagGateSpec
    {
        return $this->resolveAll()[$migrationId] ?? null;
    }

    /** @return array<string, ?FlagGateSpec> */
    private function resolveAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];
        $descriptors = $this->registry->descriptorsByClass();

        foreach ($descriptors as $class => $descriptor) {
            $this->cache[$class] = $this->resolveSpecForClass($class);
        }

        return $this->cache;
    }

    private function resolveSpecForClass(string $class): ?FlagGateSpec
    {
        if (!class_exists($class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(GatedByFlag::class);

        if ($attributes === []) {
            return null;
        }

        /** @var GatedByFlag $attr */
        $attr = $attributes[0]->newInstance();

        return new FlagGateSpec($attr->flagName, $attr->oldVariant);
    }
}
