<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Queue;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Export\PlaceholderTranslator;

final class QueueExporterDefinition extends AbstractExporterDefinition
{
    private ?QueueProvider $provider = null;
    private ?string $queueName = null;
    private ?int $visibilityTimeoutSeconds = null;
    private ?int $messageRetentionSeconds = null;
    private ?int $ackDeadlineSeconds = null;
    private ?string $topicRef = null;

    public function provider(QueueProvider $provider): static { $this->provider = $provider; return $this; }
    public function queueName(string $name): static { $this->queueName = $name; return $this; }
    public function visibilityTimeoutSeconds(int $seconds): static { $this->visibilityTimeoutSeconds = $seconds; return $this; }
    public function messageRetentionSeconds(int $seconds): static { $this->messageRetentionSeconds = $seconds; return $this; }
    public function ackDeadlineSeconds(int $seconds): static { $this->ackDeadlineSeconds = $seconds; return $this; }
    public function topicRef(string $ref): static { $this->topicRef = $ref; return $this; }

    public function exporterClass(): string { return QueueExporter::class; }

    public function compileSpec(ContainerBuilder $container): array
    {
        if ($this->provider === null) {
            throw new \LogicException(sprintf("Queue exporter '%s' declares no provider().", $this->name));
        }
        $context = sprintf("Queue exporter '%s'", $this->name);
        $spec = ['provider' => $this->provider->value, 'label' => str_replace('-', '_', $this->name)];
        if ($this->queueName !== null) { $spec['queue_name'] = PlaceholderTranslator::translate($this->queueName, $container, $context); }
        if ($this->visibilityTimeoutSeconds !== null) { $spec['visibility_timeout_seconds'] = $this->visibilityTimeoutSeconds; }
        if ($this->messageRetentionSeconds !== null) { $spec['message_retention_seconds'] = $this->messageRetentionSeconds; }
        if ($this->ackDeadlineSeconds !== null) { $spec['ack_deadline_seconds'] = $this->ackDeadlineSeconds; }
        if ($this->topicRef !== null) { $spec['topic_ref'] = $this->topicRef; }
        return $spec;
    }
}
