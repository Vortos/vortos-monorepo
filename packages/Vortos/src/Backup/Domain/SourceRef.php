<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

/**
 * The point-in-time anchor a backup was taken at, used for PITR restore math.
 *
 *  - Postgres physical base / WAL: a WAL LSN (e.g. `0/16B6B50`).
 *  - Mongo: an oplog timestamp.
 *  - Logical dumps that carry no precise anchor: {@see none()}.
 */
final readonly class SourceRef
{
    public const TYPE_NONE = 'none';
    public const TYPE_WAL_LSN = 'wal_lsn';
    public const TYPE_OPLOG_TS = 'oplog_ts';

    private function __construct(
        public string $type,
        public ?string $value,
    ) {}

    public static function none(): self
    {
        return new self(self::TYPE_NONE, null);
    }

    public static function walLsn(string $lsn): self
    {
        return new self(self::TYPE_WAL_LSN, $lsn);
    }

    public static function oplogTimestamp(string $ts): self
    {
        return new self(self::TYPE_OPLOG_TS, $ts);
    }

    public function isAnchored(): bool
    {
        return $this->type !== self::TYPE_NONE && $this->value !== null;
    }

    /** @return array{type:string, value:?string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }

    /** @param array{type?:string, value?:?string} $data */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? self::TYPE_NONE;
        $value = $data['value'] ?? null;

        return match ($type) {
            self::TYPE_WAL_LSN => self::walLsn((string) $value),
            self::TYPE_OPLOG_TS => self::oplogTimestamp((string) $value),
            default => self::none(),
        };
    }
}
