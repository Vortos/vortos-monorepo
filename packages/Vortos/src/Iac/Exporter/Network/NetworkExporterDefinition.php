<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Network;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Export\PlaceholderTranslator;

final class NetworkExporterDefinition extends AbstractExporterDefinition
{
    private ?NetworkProvider $provider = null;
    private ?string $vpcCidr = null;
    /** @var list<string> */
    private array $subnetCidrs = [];
    private ?string $region = null;

    public function provider(NetworkProvider $provider): static { $this->provider = $provider; return $this; }
    public function vpcCidr(string $cidr): static { $this->vpcCidr = $cidr; return $this; }
    public function subnetCidrs(string ...$cidrs): static { $this->subnetCidrs = array_values($cidrs); return $this; }
    public function region(string $region): static { $this->region = $region; return $this; }

    public function exporterClass(): string { return NetworkExporter::class; }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf("Network exporter '%s' declares no provider().", $this->name));
        }

        $context = sprintf("Network exporter '%s'", $this->name);
        $spec = ['provider' => $this->provider->value, 'label' => str_replace('-', '_', $this->name)];
        if ($this->vpcCidr !== null) { $spec['vpc_cidr'] = $this->vpcCidr; }
        if ($this->subnetCidrs !== []) { $spec['subnet_cidrs'] = $this->subnetCidrs; }
        if ($this->region !== null) { $spec['region'] = PlaceholderTranslator::translate($this->region, $container, $context); }
        return $spec;
    }
}
