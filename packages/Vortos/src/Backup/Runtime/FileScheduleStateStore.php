<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

use DateTimeImmutable;

/**
 * Filesystem-backed watermark store — durable across worker restarts without a database dependency
 * (the backup node may run before the app DB is reachable). One JSON document, written atomically
 * (temp file + rename) under an exclusive lock so a crash mid-write can never corrupt it.
 */
final class FileScheduleStateStore implements ScheduleStateStoreInterface
{
    public function __construct(private readonly string $path)
    {
    }

    public function get(string $scheduleName): ScheduleState
    {
        $all = $this->load();
        $row = $all[$scheduleName] ?? null;
        if (!is_array($row)) {
            return new ScheduleState();
        }

        return new ScheduleState(
            lastFiredAt: $this->parse($row['last_fired_at'] ?? null),
            consecutiveFailures: (int) ($row['consecutive_failures'] ?? 0),
            retryAfter: $this->parse($row['retry_after'] ?? null),
        );
    }

    public function put(string $scheduleName, ScheduleState $state): void
    {
        $all = $this->load();
        $all[$scheduleName] = [
            'last_fired_at' => $state->lastFiredAt?->format(\DateTimeInterface::ATOM),
            'consecutive_failures' => $state->consecutiveFailures,
            'retry_after' => $state->retryAfter?->format(\DateTimeInterface::ATOM),
        ];

        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $bytes = file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($bytes === false) {
            throw new \RuntimeException(sprintf('Cannot write backup schedule state to "%s".', $tmp));
        }

        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Cannot commit backup schedule state to "%s".', $this->path));
        }
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->path), true);

        return is_array($data) ? $data : [];
    }

    private function parse(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
