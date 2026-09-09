<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\FlagSync;

use Vortos\Messaging\Attribute\MessagingConfig;
use Vortos\Messaging\Attribute\RegisterConsumer;
use Vortos\Messaging\Driver\Kafka\Definition\KafkaConsumerDefinition;

/**
 * Delivers flag lifecycle events to {@see PosthogFlagSyncListener}, so a flag created or
 * changed in Vortos appears in PostHog without anyone running a command.
 *
 * ## Why in-process rather than a topic
 *
 * A topic would keep the outbound call off the writer's request, which is the textbook
 * answer for third-party I/O. It is the wrong answer here for one reason: a consumer nobody
 * runs is indistinguishable from a feature that works. This codebase has already paid for
 * that lesson twice — flag webhooks sat inert behind a missing registration, and a batch of
 * event handlers never fired because no consumer group was wired — and in both cases the
 * failure was silent for months. An in-process handler cannot be silently unwired: if flag
 * writes happen, this runs.
 *
 * The price is that a slow PostHog is felt by whoever changed the flag. That is bounded to a
 * few seconds by the sync client's own timeouts, it fails soft (the flag change is already
 * committed; only the mirror is skipped), and a flag change is a rare, interactive,
 * human-initiated act. `vortos:flags:posthog:sync` is the backstop that repairs anything a
 * failed call skipped, and is the thing to schedule if you want a guarantee rather than
 * best-effort.
 */
#[MessagingConfig]
final class PosthogFlagSyncMessagingConfig
{
    public const CONSUMER = 'vortos.analytics_posthog.flag_sync';

    #[RegisterConsumer]
    public function flagSyncConsumer(): KafkaConsumerDefinition
    {
        return KafkaConsumerDefinition::create(self::CONSUMER)
            ->inProcess()
            ->parallelism(1)
            ->batchSize(1);
    }
}
