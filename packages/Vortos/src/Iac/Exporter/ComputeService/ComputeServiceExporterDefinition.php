<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\ComputeService;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Export\PlaceholderTranslator;

final class ComputeServiceExporterDefinition extends AbstractExporterDefinition
{
    private ?ComputeServiceProvider $provider = null;
    private ?string $containerImage = null;
    private ?int $containerPort = null;
    private ?int $cpu = null;
    private ?int $memory = null;
    private ?string $clusterRef = null;
    private ?string $region = null;

    public function provider(ComputeServiceProvider $provider): static { $this->provider = $provider; return $this; }
    public function containerImage(string $image): static { $this->containerImage = $image; return $this; }
    public function containerPort(int $port): static { $this->containerPort = $port; return $this; }
    public function cpu(int $cpu): static { $this->cpu = $cpu; return $this; }
    public function memory(int $memory): static { $this->memory = $memory; return $this; }
    public function clusterRef(string $ref): static { $this->clusterRef = $ref; return $this; }
    public function region(string $region): static { $this->region = $region; return $this; }

    public function exporterClass(): string
    {
        return ComputeServiceExporter::class;
    }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf("Compute service exporter '%s' declares no provider().", $this->name));
        }

        $context = sprintf("Compute service exporter '%s'", $this->name);

        $spec = [
            'provider' => $this->provider->value,
            'label' => str_replace('-', '_', $this->name),
        ];

        if ($this->containerImage !== null) {
            $spec['container_image'] = PlaceholderTranslator::translate($this->containerImage, $container, $context);
        }
        if ($this->containerPort !== null) {
            $spec['container_port'] = $this->containerPort;
        }
        if ($this->cpu !== null) {
            $spec['cpu'] = $this->cpu;
        }
        if ($this->memory !== null) {
            $spec['memory'] = $this->memory;
        }
        if ($this->clusterRef !== null) {
            $spec['cluster_ref'] = $this->clusterRef;
        }
        if ($this->region !== null) {
            $spec['region'] = PlaceholderTranslator::translate($this->region, $container, $context);
        }

        return $spec;
    }
}
