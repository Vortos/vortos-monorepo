<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * The repository's own account of itself: `pgbackrest info --output=json`, for one stanza.
 *
 * Only what the framework acts on is modelled. Status code 0 is "ok"; anything else (missing stanza,
 * no valid backups, mixed state) is the repository saying it cannot restore, in its own words.
 */
final readonly class PgBackRestInfo
{
    /**
     * @param list<PgBackRestBackupSet> $backups oldest first, as pgBackRest lists them
     */
    public function __construct(
        public string $stanza,
        public int $statusCode,
        public string $statusMessage,
        public ?string $cipher,
        public array $backups,
        /** Oldest and newest WAL file archived for the current database (system id + version). */
        public ?string $archiveMin,
        public ?string $archiveMax,
    ) {}

    public static function fromJson(string $json, string $stanza): self
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new \RuntimeException('pgbackrest info did not return JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!\is_array($decoded)) {
            throw new \RuntimeException('pgbackrest info returned no stanza list.');
        }

        foreach ($decoded as $entry) {
            if (\is_array($entry) && ($entry['name'] ?? null) === $stanza) {
                return self::fromStanza($entry, $stanza);
            }
        }

        throw new \RuntimeException(sprintf('pgbackrest info does not list stanza "%s".', $stanza));
    }

    /**
     * @param array<mixed> $entry
     */
    private static function fromStanza(array $entry, string $stanza): self
    {
        $status = \is_array($entry['status'] ?? null) ? $entry['status'] : [];
        $databases = \is_array($entry['db'] ?? null) ? $entry['db'] : [];
        // The current database is the last one listed: an upgrade appends, it never reorders.
        $current = end($databases);
        $currentId = \is_array($current) ? ($current['id'] ?? null) : null;

        $archiveMin = null;
        $archiveMax = null;
        foreach (\is_array($entry['archive'] ?? null) ? $entry['archive'] : [] as $archive) {
            if (\is_array($archive) && \is_array($archive['database'] ?? null) && ($archive['database']['id'] ?? null) === $currentId) {
                $archiveMin = \is_string($archive['min'] ?? null) ? $archive['min'] : null;
                $archiveMax = \is_string($archive['max'] ?? null) ? $archive['max'] : null;
            }
        }

        $backups = [];
        foreach (\is_array($entry['backup'] ?? null) ? $entry['backup'] : [] as $backup) {
            if (\is_array($backup)) {
                $backups[] = self::backupSet($backup);
            }
        }

        return new self(
            $stanza,
            \is_int($status['code'] ?? null) ? $status['code'] : -1,
            \is_string($status['message'] ?? null) ? $status['message'] : 'no status reported',
            \is_string($entry['cipher'] ?? null) ? $entry['cipher'] : null,
            $backups,
            $archiveMin,
            $archiveMax,
        );
    }

    /**
     * @param array<mixed> $b
     */
    private static function backupSet(array $b): PgBackRestBackupSet
    {
        $timestamp = \is_array($b['timestamp'] ?? null) ? $b['timestamp'] : [];
        $archive = \is_array($b['archive'] ?? null) ? $b['archive'] : [];
        $lsn = \is_array($b['lsn'] ?? null) ? $b['lsn'] : [];
        $info = \is_array($b['info'] ?? null) ? $b['info'] : [];
        $repository = \is_array($info['repository'] ?? null) ? $info['repository'] : [];

        $label = $b['label'] ?? null;
        $type = PgBackRestBackupType::tryFrom(\is_string($b['type'] ?? null) ? $b['type'] : '');
        if (!\is_string($label) || $type === null || !\is_int($timestamp['start'] ?? null) || !\is_int($timestamp['stop'] ?? null)
            || !\is_string($archive['start'] ?? null) || !\is_string($archive['stop'] ?? null)) {
            throw new \RuntimeException('pgbackrest info lists a backup without a label, type, timestamps or WAL range.');
        }

        $utc = new DateTimeZone('UTC');

        return new PgBackRestBackupSet(
            $label,
            $type,
            (new DateTimeImmutable('@' . $timestamp['start']))->setTimezone($utc),
            (new DateTimeImmutable('@' . $timestamp['stop']))->setTimezone($utc),
            $archive['start'],
            $archive['stop'],
            \is_string($lsn['start'] ?? null) ? $lsn['start'] : null,
            \is_string($lsn['stop'] ?? null) ? $lsn['stop'] : null,
            \is_int($info['size'] ?? null) ? $info['size'] : 0,
            \is_int($repository['delta'] ?? null) ? $repository['delta'] : 0,
            ($b['error'] ?? false) === true,
        );
    }

    public function isOk(): bool
    {
        return $this->statusCode === 0;
    }

    /** The newest backup a restore can trust, of any type or of the given ones. */
    public function newest(PgBackRestBackupType ...$types): ?PgBackRestBackupSet
    {
        $newest = null;
        foreach ($this->backups as $backup) {
            if ($backup->error || ($types !== [] && !\in_array($backup->type, $types, true))) {
                continue;
            }
            if ($newest === null || $backup->stoppedAt >= $newest->stoppedAt) {
                $newest = $backup;
            }
        }

        return $newest;
    }
}
