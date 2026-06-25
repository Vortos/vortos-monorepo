<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Database;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Export\PlaceholderTranslator;

final class DatabaseExporterDefinition extends AbstractExporterDefinition
{
    private ?DatabaseProvider $provider = null;
    private ?string $engine = null;
    private ?string $engineVersion = null;
    private ?string $instanceClass = null;
    private ?int $allocatedStorage = null;
    private ?string $tier = null;
    private ?string $region = null;

    public function provider(DatabaseProvider $provider): static { $this->provider = $provider; return $this; }
    public function engine(string $engine): static { $this->engine = $engine; return $this; }
    public function engineVersion(string $version): static { $this->engineVersion = $version; return $this; }
    public function instanceClass(string $class): static { $this->instanceClass = $class; return $this; }
    public function allocatedStorage(int $gb): static { $this->allocatedStorage = $gb; return $this; }
    public function tier(string $tier): static { $this->tier = $tier; return $this; }
    public function region(string $region): static { $this->region = $region; return $this; }

    public function exporterClass(): string { return DatabaseExporter::class; }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf("Database exporter '%s' declares no provider().", $this->name));
        }
        $context = sprintf("Database exporter '%s'", $this->name);
        $spec = ['provider' => $this->provider->value, 'label' => str_replace('-', '_', $this->name)];
        if ($this->engine !== null) { $spec['engine'] = $this->engine; }
        if ($this->engineVersion !== null) { $spec['engine_version'] = $this->engineVersion; }
        if ($this->instanceClass !== null) { $spec['instance_class'] = PlaceholderTranslator::translate($this->instanceClass, $container, $context); }
        if ($this->allocatedStorage !== null) { $spec['allocated_storage'] = $this->allocatedStorage; }
        if ($this->tier !== null) { $spec['tier'] = PlaceholderTranslator::translate($this->tier, $container, $context); }
        if ($this->region !== null) { $spec['region'] = PlaceholderTranslator::translate($this->region, $container, $context); }
        return $spec;
    }
}
