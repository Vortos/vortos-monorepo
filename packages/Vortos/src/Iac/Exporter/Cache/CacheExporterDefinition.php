<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Cache;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Export\PlaceholderTranslator;

final class CacheExporterDefinition extends AbstractExporterDefinition
{
    private ?CacheProvider $provider = null;
    private ?string $nodeType = null;
    private ?int $numCacheNodes = null;
    private ?int $memorySizeGb = null;
    private ?string $region = null;
    private ?string $engineVersion = null;

    public function provider(CacheProvider $provider): static { $this->provider = $provider; return $this; }
    public function nodeType(string $type): static { $this->nodeType = $type; return $this; }
    public function numCacheNodes(int $count): static { $this->numCacheNodes = $count; return $this; }
    public function memorySizeGb(int $gb): static { $this->memorySizeGb = $gb; return $this; }
    public function region(string $region): static { $this->region = $region; return $this; }
    public function engineVersion(string $version): static { $this->engineVersion = $version; return $this; }

    public function exporterClass(): string { return CacheExporter::class; }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf("Cache exporter '%s' declares no provider().", $this->name));
        }
        $context = sprintf("Cache exporter '%s'", $this->name);
        $spec = ['provider' => $this->provider->value, 'label' => str_replace('-', '_', $this->name)];
        if ($this->nodeType !== null) { $spec['node_type'] = PlaceholderTranslator::translate($this->nodeType, $container, $context); }
        if ($this->numCacheNodes !== null) { $spec['num_cache_nodes'] = $this->numCacheNodes; }
        if ($this->memorySizeGb !== null) { $spec['memory_size_gb'] = $this->memorySizeGb; }
        if ($this->region !== null) { $spec['region'] = PlaceholderTranslator::translate($this->region, $container, $context); }
        if ($this->engineVersion !== null) { $spec['engine_version'] = $this->engineVersion; }
        return $spec;
    }
}
