<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Dns;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Export\PlaceholderTranslator;

final class DnsExporterDefinition extends AbstractExporterDefinition
{
    private ?DnsProvider $provider = null;
    private ?string $zoneName = null;
    /** @var list<array{name: string, type: string, value: string, ttl?: int}> */
    private array $records = [];
    private bool $managedCert = false;
    private ?string $certDomain = null;
    private ?string $zoneId = null;

    public function provider(DnsProvider $provider): static { $this->provider = $provider; return $this; }
    public function zoneName(string $name): static { $this->zoneName = $name; return $this; }
    public function record(string $name, string $type, string $value, int $ttl = 300): static
    {
        $this->records[] = ['name' => $name, 'type' => $type, 'value' => $value, 'ttl' => $ttl];
        return $this;
    }
    public function managedCert(string $domain): static { $this->managedCert = true; $this->certDomain = $domain; return $this; }
    public function zoneId(string $id): static { $this->zoneId = $id; return $this; }

    public function exporterClass(): string { return DnsExporter::class; }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf("DNS exporter '%s' declares no provider().", $this->name));
        }
        $context = sprintf("DNS exporter '%s'", $this->name);
        $spec = ['provider' => $this->provider->value, 'label' => str_replace('-', '_', $this->name)];
        if ($this->zoneName !== null) { $spec['zone_name'] = $this->zoneName; }
        if ($this->zoneId !== null) { $spec['zone_id'] = PlaceholderTranslator::translate($this->zoneId, $container, $context); }
        $spec['records'] = $this->records;
        $spec['managed_cert'] = $this->managedCert;
        if ($this->certDomain !== null) { $spec['cert_domain'] = $this->certDomain; }
        return $spec;
    }
}
