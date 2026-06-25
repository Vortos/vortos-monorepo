<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\StateBackend;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Export\PlaceholderTranslator;

final class StateBackendExporterDefinition extends AbstractExporterDefinition
{
    private ?StateBackendProvider $provider = null;
    private ?string $bucket = null;
    private ?string $key = null;
    private ?string $region = null;
    private ?string $dynamodbTable = null;
    private ?string $prefix = null;
    private ?string $environment = null;

    public function provider(StateBackendProvider $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function bucket(string $bucket): static
    {
        $this->bucket = $bucket;
        return $this;
    }

    public function key(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function region(string $region): static
    {
        $this->region = $region;
        return $this;
    }

    public function dynamodbTable(string $table): static
    {
        $this->dynamodbTable = $table;
        return $this;
    }

    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function environment(string $environment): static
    {
        $this->environment = $environment;
        return $this;
    }

    public function exporterClass(): string
    {
        return StateBackendExporter::class;
    }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf(
                "State backend exporter '%s' declares no provider().",
                $this->name,
            ));
        }

        $env = $this->environment ?? 'default';

        if ($this->provider !== StateBackendProvider::Local) {
            (new StateBackendValidator())->validate($this->provider, $env);
        }

        $context = sprintf("State backend exporter '%s'", $this->name);

        return match ($this->provider) {
            StateBackendProvider::S3Dynamodb => $this->compileS3($container, $context),
            StateBackendProvider::Gcs => $this->compileGcs($container, $context),
            StateBackendProvider::Local => ['provider' => 'local', 'path' => 'terraform.tfstate'],
        };
    }

    /** @return array<string, mixed> */
    private function compileS3(ContainerBuilder $container, string $context): array
    {
        if ($this->bucket === null) {
            throw new \LogicException(sprintf('%s: S3 backend requires bucket().', $context));
        }

        $spec = [
            'provider' => 's3',
            'bucket' => PlaceholderTranslator::translate($this->bucket, $container, $context),
            'key' => $this->key ?? 'terraform.tfstate',
        ];

        if ($this->region !== null) {
            $spec['region'] = PlaceholderTranslator::translate($this->region, $container, $context);
        }

        if ($this->dynamodbTable !== null) {
            $spec['dynamodb_table'] = PlaceholderTranslator::translate($this->dynamodbTable, $container, $context);
        }

        return $spec;
    }

    /** @return array<string, mixed> */
    private function compileGcs(ContainerBuilder $container, string $context): array
    {
        if ($this->bucket === null) {
            throw new \LogicException(sprintf('%s: GCS backend requires bucket().', $context));
        }

        $spec = [
            'provider' => 'gcs',
            'bucket' => PlaceholderTranslator::translate($this->bucket, $container, $context),
        ];

        if ($this->prefix !== null) {
            $spec['prefix'] = PlaceholderTranslator::translate($this->prefix, $container, $context);
        }

        return $spec;
    }
}
