<?php

declare(strict_types=1);

namespace Vortos\Analytics\Event;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One product event: `$distinctId` did `$name` carrying `$properties`.
 *
 * All collections are bounded at construction ({@see PropertyBounds}) — an
 * oversized payload is truncated deterministically, never forwarded and never
 * thrown (a cost guard AND a DoS guard). `fromArray()` returns null on malformed
 * input so the caller can skip junk without failing a whole batch (mirrors
 * `ExposureEvent::fromArray()`).
 */
final readonly class AnalyticsEvent
{
    public const MAX_NAME_LENGTH = 200;
    public const MAX_PROPERTIES = 100;
    public const MAX_PROPERTY_BYTES = 16384;

    public DistinctId $distinctId;
    public string $name;

    /** @var array<string,mixed> */
    public array $properties;
    public ?DateTimeImmutable $timestamp;

    /** @var array<string,string> */
    public array $groups;

    /**
     * @param array<array-key,mixed> $properties untrusted: bounded/shaped, not assumed string-keyed
     * @param array<array-key,mixed> $groups     untrusted: validated below, not assumed array<string,string>
     */
    public function __construct(
        DistinctId $distinctId,
        string $name,
        array $properties = [],
        ?DateTimeImmutable $timestamp = null,
        array $groups = [],
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('AnalyticsEvent name must not be empty.');
        }

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'AnalyticsEvent name must not exceed %d bytes, got %d.',
                self::MAX_NAME_LENGTH,
                strlen($name),
            ));
        }

        $validGroups = [];
        foreach ($groups as $groupType => $groupKey) {
            if (!is_string($groupType) || $groupType === '' || !is_string($groupKey) || $groupKey === '') {
                throw new InvalidArgumentException('AnalyticsEvent groups must be non-empty string => string pairs.');
            }
            $validGroups[$groupType] = $groupKey;
        }

        $this->distinctId = $distinctId;
        $this->name = $name;
        $this->properties = PropertyBounds::bound($properties, self::MAX_PROPERTIES, self::MAX_PROPERTY_BYTES);
        $this->timestamp = $timestamp;
        $this->groups = $validGroups;
    }

    /**
     * Build from a decoded JSON item, or null if malformed. Properties/groups are
     * bounded here (never thrown) so a flood of junk is silently shaped down.
     *
     * @param array<string,mixed> $item
     */
    public static function fromArray(array $item): ?self
    {
        $distinctIdRaw = $item['distinctId'] ?? null;
        $name = $item['name'] ?? null;

        if (!is_string($distinctIdRaw) || $distinctIdRaw === '' || strlen($distinctIdRaw) > DistinctId::MAX_LENGTH) {
            return null;
        }
        if (!is_string($name) || $name === '' || strlen($name) > self::MAX_NAME_LENGTH) {
            return null;
        }

        $properties = is_array($item['properties'] ?? null) ? $item['properties'] : [];

        $groupsRaw = is_array($item['groups'] ?? null) ? $item['groups'] : [];
        $groups = [];
        foreach ($groupsRaw as $groupType => $groupKey) {
            if (is_string($groupType) && $groupType !== '' && is_string($groupKey) && $groupKey !== '') {
                $groups[$groupType] = $groupKey;
            }
        }

        $timestampRaw = $item['timestamp'] ?? null;
        $timestamp = null;
        if (is_int($timestampRaw) || is_numeric($timestampRaw)) {
            $timestamp = (new DateTimeImmutable())->setTimestamp((int) $timestampRaw);
        }

        try {
            return new self(new DistinctId($distinctIdRaw), $name, $properties, $timestamp, $groups);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
