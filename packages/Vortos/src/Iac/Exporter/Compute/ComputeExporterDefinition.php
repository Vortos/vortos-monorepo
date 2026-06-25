<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Compute;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Export\PlaceholderTranslator;

final class ComputeExporterDefinition extends AbstractExporterDefinition
{
    private ?ComputeProvider $provider = null;
    private ?string $instanceType = null;
    private ?string $ami = null;
    private ?string $machineType = null;
    private ?string $zone = null;
    private ?string $image = null;
    private ?string $subnetRef = null;
    private ?string $keyName = null;

    public function provider(ComputeProvider $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function instanceType(string $type): static
    {
        $this->instanceType = $type;
        return $this;
    }

    public function ami(string $ami): static
    {
        $this->ami = $ami;
        return $this;
    }

    public function machineType(string $type): static
    {
        $this->machineType = $type;
        return $this;
    }

    public function zone(string $zone): static
    {
        $this->zone = $zone;
        return $this;
    }

    public function image(string $image): static
    {
        $this->image = $image;
        return $this;
    }

    public function subnetRef(string $ref): static
    {
        $this->subnetRef = $ref;
        return $this;
    }

    public function keyName(string $key): static
    {
        $this->keyName = $key;
        return $this;
    }

    public function exporterClass(): string
    {
        return ComputeExporter::class;
    }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf("Compute exporter '%s' declares no provider().", $this->name));
        }

        $context = sprintf("Compute exporter '%s'", $this->name);

        $spec = [
            'provider' => $this->provider->value,
            'label' => str_replace('-', '_', $this->name),
        ];

        if ($this->instanceType !== null) {
            $spec['instance_type'] = PlaceholderTranslator::translate($this->instanceType, $container, $context);
        }
        if ($this->ami !== null) {
            $spec['ami'] = PlaceholderTranslator::translate($this->ami, $container, $context);
        }
        if ($this->machineType !== null) {
            $spec['machine_type'] = PlaceholderTranslator::translate($this->machineType, $container, $context);
        }
        if ($this->zone !== null) {
            $spec['zone'] = PlaceholderTranslator::translate($this->zone, $container, $context);
        }
        if ($this->image !== null) {
            $spec['image'] = PlaceholderTranslator::translate($this->image, $container, $context);
        }
        if ($this->subnetRef !== null) {
            $spec['subnet_ref'] = $this->subnetRef;
        }
        if ($this->keyName !== null) {
            $spec['key_name'] = PlaceholderTranslator::translate($this->keyName, $container, $context);
        }

        return $spec;
    }
}
