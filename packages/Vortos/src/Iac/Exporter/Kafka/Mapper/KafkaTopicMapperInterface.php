<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Kafka\Mapper;

use Vortos\Iac\Exporter\Kafka\KafkaProvider;
use Vortos\Iac\Terraform\TerraformDocument;

/**
 * Maps one topic spec onto provider-specific Terraform resources.
 * One implementation per KafkaProvider case.
 */
interface KafkaTopicMapperInterface
{
    public function supports(KafkaProvider $provider): bool;

    public function declareProvider(TerraformDocument $document): void;

    /**
     * @param array{label: string, name: string, partitions: mixed, replication: mixed, config: array<string, mixed>} $topic
     *        spec-encoded values (see SpecValue)
     * @param mixed $clusterRef spec-encoded cluster reference or null
     */
    public function map(array $topic, mixed $clusterRef, TerraformDocument $document): void;
}
