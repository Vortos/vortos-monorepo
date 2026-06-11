<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Kafka;

/**
 * Terraform provider used to manage Kafka topics.
 *
 *  - Confluent: confluentinc/confluent — Confluent Cloud.
 *  - Kafka: Mongey/kafka — self-hosted clusters and AWS MSK (AWS's own
 *    provider has no topic resource; Mongey/kafka is the community standard).
 */
enum KafkaProvider: string
{
    case Confluent = 'confluent';
    case Kafka = 'kafka';
}
