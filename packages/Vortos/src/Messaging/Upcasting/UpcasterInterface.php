<?php

declare(strict_types=1);

namespace Vortos\Messaging\Upcasting;

/**
 * Transforms a decoded event payload array from one schema version to the
 * next (vN → vN+1) before hydration into the consumer's contract class.
 *
 * Upcasters are pure functions over arrays: no services, no I/O, no state —
 * they are instantiated directly by the runtime and must have a
 * dependency-free constructor. Register them per wire event in the consumer
 * definition:
 *
 *   ->upcast('registration.entry_approved', from: 1, to: 2, upcaster: EntryApprovedV1ToV2::class)
 *
 * Example:
 *
 *   final class EntryApprovedV1ToV2 implements UpcasterInterface
 *   {
 *       public function upcast(array $payload): array
 *       {
 *           [$first, $last] = explode(' ', $payload['name'] . ' ', 2);
 *           unset($payload['name']);
 *           return $payload + ['firstName' => $first, 'lastName' => trim($last)];
 *       }
 *   }
 *
 * Old messages keep flowing forever: a v1 message in Kafka, the outbox, or
 * the DLQ replays cleanly through the chain long after the producer moved on.
 */
interface UpcasterInterface
{
    /**
     * @param array<string, mixed> $payload Decoded JSON of the older version
     * @return array<string, mixed> Payload shaped for the next version
     */
    public function upcast(array $payload): array;
}
