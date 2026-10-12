<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

/**
 * `pgbackrest info --output=json` as production printed it on 2026-10-12 after the first full backup
 * (2 MiB WAL segments), with a builder for further backups and archive ends.
 */
final class PgBackRestInfoFixture
{
    /** @var list<array<string, mixed>> */
    private array $backups = [];

    private string $archiveMax = '0000000100000015000003CB';
    private int $statusCode = 0;
    private string $statusMessage = 'ok';

    public static function production(): self
    {
        return (new self())->withBackup('20261012-151549F', 'full', 1791818149, 1791818159, '0000000100000015000003C4', '15/78800028', '15/78801330');
    }

    public function withBackup(string $label, string $type, int $start, int $stop, string $walStop, string $lsnStart = '15/78800028', string $lsnStop = '15/78801330', bool $error = false): self
    {
        $clone = clone $this;
        $clone->backups[] = [
            'archive' => ['start' => $walStop, 'stop' => $walStop],
            'backrest' => ['format' => 5, 'version' => '2.59.1'],
            'database' => ['id' => 1, 'repo-key' => 1],
            'error' => $error,
            'info' => ['delta' => 371002462, 'repository' => ['delta' => 52227936, 'delta-map' => 158096, 'size-map' => 158096], 'size' => 371002462],
            'label' => $label,
            'lsn' => ['start' => $lsnStart, 'stop' => $lsnStop],
            'prior' => null,
            'reference' => null,
            'timestamp' => ['start' => $start, 'stop' => $stop],
            'type' => $type,
        ];

        return $clone;
    }

    public function withArchiveMax(string $max): self
    {
        $clone = clone $this;
        $clone->archiveMax = $max;

        return $clone;
    }

    public function withStatus(int $code, string $message): self
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->statusMessage = $message;

        return $clone;
    }

    public function json(): string
    {
        return (string) json_encode([[
            'archive' => [['database' => ['id' => 1, 'repo-key' => 1], 'id' => '18-1', 'max' => $this->archiveMax, 'min' => '0000000100000015000003C1']],
            'backup' => $this->backups,
            'cipher' => 'aes-256-cbc',
            'db' => [['id' => 1, 'repo-key' => 1, 'system-id' => 7684685997218263085, 'version' => '18']],
            'name' => 'sqoura',
            'repo' => [['cipher' => 'aes-256-cbc', 'key' => 1, 'status' => ['code' => 0, 'message' => 'ok']]],
            'status' => ['code' => $this->statusCode, 'lock' => ['backup' => ['held' => false], 'restore' => ['held' => false]], 'message' => $this->statusMessage],
        ]], JSON_THROW_ON_ERROR);
    }
}
