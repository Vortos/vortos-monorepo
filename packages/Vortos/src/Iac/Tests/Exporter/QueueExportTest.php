<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Exporter\Queue\QueueExporter;

final class QueueExportTest extends TestCase
{
    public function test_sqs_queue(): void
    {
        $doc = (new QueueExporter())->export([
            'spec' => ['provider' => 'aws-sqs', 'label' => 'orders', 'queue_name' => 'orders-queue', 'visibility_timeout_seconds' => 60],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(includeVariables: false), true);
        $this->assertArrayHasKey('aws_sqs_queue', $decoded['resource']);
        $this->assertSame(60, $decoded['resource']['aws_sqs_queue']['orders']['visibility_timeout_seconds']);
    }

    public function test_pubsub_topic_and_subscription(): void
    {
        $doc = (new QueueExporter())->export([
            'spec' => ['provider' => 'gcp-pubsub', 'label' => 'events', 'queue_name' => 'events-topic'],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(includeVariables: false), true);
        $this->assertArrayHasKey('google_pubsub_topic', $decoded['resource']);
        $this->assertArrayHasKey('google_pubsub_subscription', $decoded['resource']);
    }

    public function test_no_kafka_resources_emitted(): void
    {
        $doc = (new QueueExporter())->export([
            'spec' => ['provider' => 'aws-sqs', 'label' => 'test'],
            'allowed_literals' => [],
        ]);

        $rendered = $doc->render(includeVariables: false);
        $this->assertStringNotContainsString('kafka', strtolower($rendered));
    }

    public function test_count_resources(): void
    {
        $this->assertSame(1, (new QueueExporter())->countResources(['spec' => ['provider' => 'aws-sqs']]));
        $this->assertSame(2, (new QueueExporter())->countResources(['spec' => ['provider' => 'gcp-pubsub']]));
    }
}
