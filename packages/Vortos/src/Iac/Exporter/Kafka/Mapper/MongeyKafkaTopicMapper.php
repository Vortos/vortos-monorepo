<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Kafka\Mapper;

use Vortos\Iac\Export\SpecValue;
use Vortos\Iac\Exporter\Kafka\KafkaProvider;
use Vortos\Iac\Terraform\TerraformDocument;
use Vortos\Iac\Terraform\TfValueInterface;

/**
 * Mongey/kafka — kafka_topic. The community-standard provider for
 * self-hosted clusters and AWS MSK.
 */
final class MongeyKafkaTopicMapper implements KafkaTopicMapperInterface
{
    public function supports(KafkaProvider $provider): bool
    {
        return $provider === KafkaProvider::Kafka;
    }

    public function declareProvider(TerraformDocument $document): void
    {
        $document->requiredProvider('kafka', 'Mongey/kafka', '~> 0.7');
    }

    public function map(array $topic, mixed $clusterRef, TerraformDocument $document): void
    {
        $config = [];
        foreach (SpecValue::decodeMap($topic['config'], $document) as $key => $value) {
            $config[$key] = $value instanceof TfValueInterface ? $value : (string) $value;
        }

        $attributes = [
            'name' => $topic['name'],
            'partitions' => SpecValue::decode($topic['partitions'], $document),
            'replication_factor' => SpecValue::decode($topic['replication'], $document),
        ];

        if ($config !== []) {
            $attributes['config'] = $config;
        }

        $document->resource('kafka_topic', $topic['label'], $attributes);
    }
}
