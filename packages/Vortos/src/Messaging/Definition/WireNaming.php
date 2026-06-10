<?php

declare(strict_types=1);

namespace Vortos\Messaging\Definition;

/**
 * Conventions for logical event names on the wire.
 *
 * The wire identity of an event is a stable, language-neutral name plus a
 * schema version (e.g. "registration.entry_approved.v1") — NEVER a PHP class
 * name. Producers declare names via publishes()/publish() (with convention
 * fallback); consumers map names back to their OWN contract classes. The two
 * sides share only the name and the JSON shape, so producers can rename,
 * move, or restructure event classes without breaking consumers, and a
 * consumer never instantiates a class chosen by the wire.
 *
 * Convention: {module}.{snake_case_short_class_name}
 *   App\Registration\Domain\Entry\Event\EntryApproved → registration.entry_approved
 *
 * The module segment is the second namespace level (App\Registration\… →
 * registration), or the first for single-level namespaces.
 */
final class WireNaming
{
    /** Derives the conventional logical name for an event class. */
    public static function derive(string $eventClass): string
    {
        $parts = explode('\\', trim($eventClass, '\\'));
        $short = array_pop($parts);

        $module = $parts[1] ?? $parts[0] ?? 'app';

        return self::snake($module) . '.' . self::snake($short);
    }

    /** Formats the on-wire payload_type value: name + version suffix. */
    public static function format(string $name, int $version): string
    {
        return $name . '.v' . $version;
    }

    /**
     * Parses an on-wire payload_type value into [logical name, version].
     * A value without a version suffix gets version 1.
     *
     * @return array{string, int}
     */
    public static function parse(string $wireValue): array
    {
        if (preg_match('/^(.+)\.v(\d+)$/', $wireValue, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }

        return [$wireValue, 1];
    }

    /** Validates an explicit logical name: dot-separated lowercase snake segments. */
    public static function isValidName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $name) === 1;
    }

    private static function snake(string $value): string
    {
        return strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $value));
    }
}
