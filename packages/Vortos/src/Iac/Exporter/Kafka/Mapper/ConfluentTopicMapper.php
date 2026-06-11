<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Kafka\Mapper;

use Vortos\Iac\Export\SpecValue;
use Vortos\Iac\Exporter\Kafka\KafkaProvider;
use Vortos\Iac\Terraform\TerraformDocument;
use Vortos\Iac\Terraform\TfValueInterface;

/**
 * confluentinc/confluent — confluent_kafka_topic.
 *
 * Confluent Cloud manages the replication factor at cluster level, so the
 * spec's replication value is intentionally not emitted. Topic config is a
 * map(string): literal numbers are stringified; variable references
 * interpolate to strings natively.
 */
final class ConfluentTopicMapper implements KafkaTopicMapperInterface
{
    public function supports(KafkaProvider $provider): bool
    {
        return $provider === KafkaProvider::Confluent;
    }

    public function declareProvider(TerraformDocument $document): void
    {
        $document->requiredProvider('confluent', 'confluentinc/confluent', '~> 2.0');
    }

    public function map(array $topic, mixed $clusterRef, TerraformDocument $document): void
    {
        $config = [];
        foreach (SpecValue::decodeMap($topic['config'], $document) as $key => $value) {
            $config[$key] = $value instanceof TfValueInterface ? $value : (string) $value;
        }

        $attributes = [
            'topic_name' => $topic['name'],
            'partitions_count' => SpecValue::decode($topic['partitions'], $document),
        ];

        if ($clusterRef !== null) {
            $attributes['kafka_cluster'] = ['id' => SpecValue::decode($clusterRef, $document)];
        }

        if ($config !== []) {
            $attributes['config'] = $config;
        }

        $document->resource('confluent_kafka_topic', $topic['label'], $attributes);
    }
}
