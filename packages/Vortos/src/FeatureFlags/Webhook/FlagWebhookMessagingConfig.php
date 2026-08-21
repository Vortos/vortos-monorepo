<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

use Vortos\Messaging\Attribute\MessagingConfig;
use Vortos\Messaging\Attribute\RegisterConsumer;
use Vortos\Messaging\Driver\Kafka\Definition\KafkaConsumerDefinition;

/**
 * Delivers flag lifecycle events to {@see FlagEventListener}, which fans them out to the
 * subscribed webhooks.
 *
 * ## Why this file had to exist
 *
 * FlagEventListener was written, its event map was correct, and nothing ever called it. The
 * flag aggregate recorded FlagCreatedEvent and friends, EventBus looked for somewhere to send
 * them, found neither an in-process handler nor a producer, and logged
 * "Event dispatched but no handlers or producer registered" — at WARNING, once per flag change,
 * where nobody was reading. Every webhook subscription in the product was therefore inert: no
 * error, no failed delivery, no retry queue, just silence that looked like nobody had subscribed.
 *
 * That is the same failure that lost RoleGranted from the audit trail, and it is worth naming
 * the shape: a handler with no registration is not a broken handler, it is an absent one, and
 * absence is invisible in every place you would think to look.
 *
 * ## Why in-process rather than a topic
 *
 * These events do not leave the application. The listener's job is to call
 * {@see WebhookDispatcher}, which makes outbound HTTP to whoever subscribed — so a broker
 * round-trip would add a hop, a topic, a consumer group and a worker process to reach a
 * component sitting in the same request. inProcess() keeps EventBus routing them straight
 * through Symfony Messenger.
 *
 * The consequence to be aware of: in-process means the dispatch shares the caller's lifetime,
 * so a slow webhook endpoint is felt by whoever changed the flag. WebhookDispatcher is
 * responsible for its own timeouts; this config deliberately does not paper over that with a
 * queue, because a flag change is a rare, interactive, human-initiated act and the person who
 * made it is the right person to learn that their webhook endpoint is not answering.
 */
#[MessagingConfig]
final class FlagWebhookMessagingConfig
{
    public const CONSUMER = 'vortos.feature_flags.webhooks';

    #[RegisterConsumer]
    public function flagWebhookConsumer(): KafkaConsumerDefinition
    {
        return KafkaConsumerDefinition::create(self::CONSUMER)
            ->inProcess()
            ->parallelism(1)
            ->batchSize(1);
    }
}
