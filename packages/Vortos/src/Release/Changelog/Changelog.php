<?php

declare(strict_types=1);

namespace Vortos\Release\Changelog;

use Vortos\Release\Version\SemverVersion;

final readonly class Changelog
{
    private const GROUP_ORDER = [
        'breaking' => 0,
        'feat' => 1,
        'fix' => 2,
        'perf' => 3,
        'refactor' => 4,
        'docs' => 5,
        'test' => 6,
        'chore' => 7,
        'other' => 8,
    ];

    private const GROUP_LABELS = [
        'breaking' => 'Breaking Changes',
        'feat' => 'Features',
        'fix' => 'Bug Fixes',
        'perf' => 'Performance',
        'refactor' => 'Refactoring',
        'docs' => 'Documentation',
        'test' => 'Tests',
        'chore' => 'Chores',
        'other' => 'Other',
    ];

    /**
     * @param list<ChangelogEntry> $entries
     * @param array<string, list<ChangelogEntry>> $grouped
     */
    public function __construct(
        public SemverVersion $version,
        public \DateTimeImmutable $date,
        public array $entries,
        public array $grouped,
        public string $packageName,
    ) {}

    /**
     * @param list<ChangelogEntry> $entries
     */
    public static function fromEntries(
        SemverVersion $version,
        \DateTimeImmutable $date,
        array $entries,
        string $packageName,
    ): self {
        $grouped = self::groupEntries($entries);

        return new self($version, $date, $entries, $grouped, $packageName);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @param list<ChangelogEntry> $entries
     * @return array<string, list<ChangelogEntry>>
     */
    private static function groupEntries(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $key = $entry->type;
            if (!\array_key_exists($key, self::GROUP_ORDER)) {
                $key = 'other';
            }
            $groups[$key][] = $entry;
        }

        uksort($groups, static function (string $a, string $b): int {
            $aOrder = \array_key_exists($a, self::GROUP_ORDER) ? self::GROUP_ORDER[$a] : 99;
            $bOrder = \array_key_exists($b, self::GROUP_ORDER) ? self::GROUP_ORDER[$b] : 99;

            return $aOrder <=> $bOrder;
        });

        return $groups;
    }

    public static function labelForGroup(string $group): string
    {
        return self::GROUP_LABELS[$group] ?? ucfirst($group);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => (string) $this->version,
            'date' => $this->date->format('Y-m-d'),
            'package' => $this->packageName,
            'entries' => array_map(static fn (ChangelogEntry $e) => $e->toArray(), $this->entries),
        ];
    }
}
