<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Runtime;

/**
 * A consumer's static group membership id (`group.instance.id`).
 *
 * Without one, every process that stops — a memory-cap restart, a crash, a supervisor restart — leaves
 * its group and every other member of that group gives up its partitions while the coordinator
 * reassigns them all. With one, the restarted process rejoins as the same member within the session
 * timeout and takes its own partitions back; nobody else is touched.
 *
 * The id must be stable across restarts of one process slot and unique across every process consuming
 * the group at the same time. The deployment supplies the slot (VORTOS_CONSUMER_INSTANCE, e.g. the
 * container host plus supervisor's program and process number); the consumer name is appended because
 * one process can run several consumers, each a member of its own group.
 *
 * Kafka limits the id to 249 characters of [A-Za-z0-9._-]. Anything else is replaced, and an over-long id
 * keeps a stable hash suffix so two long slots never collapse onto the same member.
 */
final class ConsumerInstanceId
{
    private const MAX_LENGTH = 249;

    public static function for(string $instance, string $consumerName): string
    {
        $instance = trim($instance);
        if ($instance === '') {
            throw new \InvalidArgumentException('A consumer instance slot cannot be empty.');
        }

        $id = preg_replace('/[^A-Za-z0-9._-]/', '_', $instance . '.' . $consumerName) ?? '';

        if (strlen($id) > self::MAX_LENGTH) {
            $hash = substr(hash('sha256', $id), 0, 16);
            $id   = substr($id, 0, self::MAX_LENGTH - 17) . '-' . $hash;
        }

        return $id;
    }
}
