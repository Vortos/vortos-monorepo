<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Queue;

use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Export\SpecValue;
use Vortos\Iac\Terraform\TerraformDocument;

final class QueueExporter implements ExporterInterface
{
    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $provider = QueueProvider::from($spec['provider']);
        $document = new TerraformDocument($entry['allowed_literals'] ?? []);

        match ($provider) {
            QueueProvider::AwsSqs => $this->exportSqs($spec, $document),
            QueueProvider::GcpPubSub => $this->exportPubSub($spec, $document),
        };

        return $document;
    }

    /** @param array<string, mixed> $entry */
    public function countResources(array $entry): int
    {
        return match (QueueProvider::from($entry['spec']['provider'])) {
            QueueProvider::AwsSqs => 1,
            QueueProvider::GcpPubSub => 2,
        };
    }

    /** @param array<string, mixed> $spec */
    private function exportSqs(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('aws', 'hashicorp/aws', '~> 5.0');
        $label = $spec['label'];

        $attrs = ['name' => SpecValue::decode($spec['queue_name'] ?? $label, $document)];
        if (isset($spec['visibility_timeout_seconds'])) {
            $attrs['visibility_timeout_seconds'] = $spec['visibility_timeout_seconds'];
        }
        if (isset($spec['message_retention_seconds'])) {
            $attrs['message_retention_seconds'] = $spec['message_retention_seconds'];
        }
        $document->resource('aws_sqs_queue', $label, $attrs);
    }

    /** @param array<string, mixed> $spec */
    private function exportPubSub(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('google', 'hashicorp/google', '~> 5.0');
        $label = $spec['label'];

        $document->resource('google_pubsub_topic', $label, [
            'name' => SpecValue::decode($spec['queue_name'] ?? $label, $document),
        ]);

        $subAttrs = [
            'name' => $label . '-sub',
            'topic' => SpecValue::decode(SpecValue::ref('google_pubsub_topic.' . $label . '.name'), $document),
        ];
        if (isset($spec['ack_deadline_seconds'])) {
            $subAttrs['ack_deadline_seconds'] = $spec['ack_deadline_seconds'];
        }
        if (isset($spec['message_retention_seconds'])) {
            $subAttrs['message_retention_duration'] = $spec['message_retention_seconds'] . 's';
        }
        $document->resource('google_pubsub_subscription', $label . '_sub', $subAttrs);
    }
}
