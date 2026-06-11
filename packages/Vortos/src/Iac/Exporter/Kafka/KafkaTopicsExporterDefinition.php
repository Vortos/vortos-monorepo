<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Kafka;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Export\PlaceholderTranslator;
use Vortos\Iac\Export\SpecValue;

/**
 * Exports every Kafka transport declared in MessagingConfig classes as
 * Terraform topic resources. Reads the already-compiled vortos.transports
 * parameter — MessagingConfig needs nothing added; partitions(),
 * replicationFactor() and topicConfig() ARE the provisioning intent.
 *
 * SASL/SSL settings are never exported: they are client connection auth,
 * not topic infrastructure.
 *
 * Example:
 *   #[RegisterTerraformExporter]
 *   public function kafkaTopics(): KafkaTopicsExporterDefinition
 *   {
 *       return KafkaTopicsExporterDefinition::create('kafka-topics')
 *           ->provider(KafkaProvider::Confluent)
 *           ->clusterRef('confluent_kafka_cluster.main')
 *           ->outputFile('infra/kafka_topics.tf.json');
 *   }
 */
final class KafkaTopicsExporterDefinition extends AbstractExporterDefinition
{
    private ?KafkaProvider $provider = null;
    private ?string $clusterRef = null;

    /** @var list<string> */
    private array $only = [];

    /** @var list<string> */
    private array $exclude = [];

    public function provider(KafkaProvider $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    /**
     * Terraform address of the cluster resource topics belong to,
     * e.g. 'confluent_kafka_cluster.main'. Required for Confluent.
     */
    public function clusterRef(string $reference): static
    {
        $this->clusterRef = $reference;
        return $this;
    }

    /** Allow-list of transport-name globs. Mutually exclusive with exclude(). */
    public function only(string ...$globs): static
    {
        $this->only = array_values($globs);
        return $this;
    }

    /** Deny-list of transport-name globs. Mutually exclusive with only(). */
    public function exclude(string ...$globs): static
    {
        $this->exclude = array_values($globs);
        return $this;
    }

    public function exporterClass(): string
    {
        return KafkaTopicsExporter::class;
    }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf(
                "Kafka exporter '%s' declares no provider(). Choose KafkaProvider::Confluent or KafkaProvider::Kafka.",
                $this->name,
            ));
        }

        if ($this->only !== [] && $this->exclude !== []) {
            throw new \LogicException(sprintf(
                "Kafka exporter '%s' uses both only() and exclude() — they are mutually exclusive.",
                $this->name,
            ));
        }

        if ($this->provider === KafkaProvider::Confluent && $this->clusterRef === null) {
            throw new \LogicException(sprintf(
                "Kafka exporter '%s' targets Confluent but declares no clusterRef(). "
                . "Point it at your cluster resource, e.g. clusterRef('confluent_kafka_cluster.main').",
                $this->name,
            ));
        }

        if (!$container->hasParameter('vortos.transports')) {
            throw new \LogicException(sprintf(
                "Kafka exporter '%s' requires the messaging package (vortos.transports parameter not found).",
                $this->name,
            ));
        }

        $topics = [];
        $labels = [];

        foreach ((array) $container->getParameter('vortos.transports') as $transportName => $transport) {
            if (($transport['driver'] ?? '') !== 'kafka' || !$this->matches((string) $transportName)) {
                continue;
            }

            $context = sprintf("Kafka exporter '%s', transport '%s'", $this->name, $transportName);
            $topicName = $transport['subscription']['topic'] ?? '';

            if (!is_string($topicName) || $topicName === '' || str_contains($topicName, '%env(')) {
                throw new \LogicException(sprintf(
                    '%s: the topic name must be a non-empty literal — Terraform resource labels are static. '
                    . 'Use a literal topic name, or exclude this transport from the export.',
                    $context,
                ));
            }

            $label = self::label($topicName);

            if (isset($labels[$label])) {
                throw new \LogicException(sprintf(
                    "%s: topic '%s' and topic '%s' both sanitize to Terraform label '%s'.",
                    $context,
                    $topicName,
                    $labels[$label],
                    $label,
                ));
            }

            $labels[$label] = $topicName;

            $config = [];
            foreach ($transport['provisioning']['topic_config'] ?? [] as $key => $value) {
                $config[$key] = PlaceholderTranslator::translate($value, $container, $context);
            }

            $topics[] = [
                'label' => $label,
                'name' => $topicName,
                'partitions' => PlaceholderTranslator::translate($transport['provisioning']['partitions'] ?? 1, $container, $context),
                'replication' => PlaceholderTranslator::translate($transport['provisioning']['replication'] ?? 1, $container, $context),
                'config' => $config,
            ];
        }

        return [
            'provider' => $this->provider->value,
            'cluster_ref' => $this->clusterRef === null ? null : SpecValue::ref($this->clusterRef . '.id'),
            'topics' => $topics,
        ];
    }

    private function matches(string $transportName): bool
    {
        foreach ($this->exclude as $glob) {
            if (fnmatch($glob, $transportName)) {
                return false;
            }
        }

        if ($this->only === []) {
            return true;
        }

        foreach ($this->only as $glob) {
            if (fnmatch($glob, $transportName)) {
                return true;
            }
        }

        return false;
    }

    /** Deterministic topic-name → Terraform label sanitizer. */
    private static function label(string $topicName): string
    {
        $label = strtolower($topicName);
        $label = preg_replace('/[^a-z0-9_]+/', '_', $label);
        $label = trim((string) $label, '_');

        if ($label === '' || !preg_match('/^[a-z_]/', $label)) {
            $label = 't_' . $label;
        }

        return $label;
    }
}
