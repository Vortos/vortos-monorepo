<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Kafka;

use Vortos\Iac\Exception\IacException;
use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Exporter\Kafka\Mapper\KafkaTopicMapperInterface;
use Vortos\Iac\Terraform\TerraformDocument;

/**
 * Runtime half of the Kafka topics export: maps the compiled static spec
 * onto a Terraform document through the provider's mapper. Pure transform —
 * no I/O, no env reads.
 */
final class KafkaTopicsExporter implements ExporterInterface
{
    public function __construct(
        /** @var iterable<KafkaTopicMapperInterface> */
        private readonly iterable $mappers,
    ) {}

    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $provider = KafkaProvider::from($spec['provider']);
        $mapper = $this->mapperFor($provider);

        $document = new TerraformDocument($entry['allowed_literals'] ?? []);
        $mapper->declareProvider($document);

        foreach ($spec['topics'] as $topic) {
            $mapper->map($topic, $spec['cluster_ref'], $document);
        }

        return $document;
    }

    public function countResources(array $entry): int
    {
        return count($entry['spec']['topics']);
    }

    private function mapperFor(KafkaProvider $provider): KafkaTopicMapperInterface
    {
        foreach ($this->mappers as $mapper) {
            if ($mapper->supports($provider)) {
                return $mapper;
            }
        }

        throw new IacException(sprintf("No mapper registered for Kafka provider '%s'.", $provider->value));
    }
}
