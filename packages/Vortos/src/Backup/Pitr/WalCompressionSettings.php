<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Vortos\Backup\Domain\CompressionCodec;

/**
 * The resolved WAL compression setting — codec plus level, as one value.
 *
 * A single object rather than two constructor scalars so the pair cannot be wired apart: a level
 * silently applied to the `none` codec, or a codec wired without its level, are both configurations
 * that look correct in the container and do nothing at runtime. Resolution happens once, in
 * {@see \Vortos\Backup\Config\BackupConfigLoader::walCompression()}, where config/backup.php and the
 * environment override are reconciled — so there is exactly one answer to "what is WAL compressed
 * with", and both the archiver and the operator-facing report read it from the same place.
 */
final readonly class WalCompressionSettings
{
    public function __construct(
        public CompressionCodec $codec = CompressionCodec::None,
        public int $level = WalCompression::DEFAULT_LEVEL,
    ) {
        WalCompression::assertSupported($this->codec);
    }

    public static function disabled(): self
    {
        return new self(CompressionCodec::None);
    }

    public function enabled(): bool
    {
        return $this->codec !== CompressionCodec::None;
    }

    /** Operator-facing, for the DR runbook and `deploy:doctor` — where "off" must be legible, not implied by absence. */
    public function describe(): string
    {
        return $this->enabled()
            ? sprintf('%s (level %d)', $this->codec->value, $this->level)
            : 'none (segments ship at full 16 MiB)';
    }
}
