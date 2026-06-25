<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Queue;

enum QueueProvider: string
{
    case AwsSqs = 'aws-sqs';
    case GcpPubSub = 'gcp-pubsub';
}
