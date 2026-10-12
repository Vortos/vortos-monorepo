<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use DateTimeImmutable;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;

/** The newest physical_base in the catalog, and the WAL segments catalogued since it. */
final class CatalogPitrAnchorReader implements PitrAnchorReaderInterface
{
    public function __construct(
        private readonly BackupCatalogReadModelInterface $catalog,
        private readonly WalVolumeReadModelInterface $wal,
        private readonly string $environment,
    ) {}

    public function read(DateTimeImmutable $now): PitrAnchorReading
    {
        $anchor = $this->catalog->latestOfKind(DatabaseEngine::Postgres, $this->environment, [BackupKind::PhysicalBase]);
        if ($anchor === null) {
            return PitrAnchorReading::none('no physical base backup exists, so there is no point-in-time recovery to time');
        }

        $sinceAnchor = $this->wal->walVolumeSince(DatabaseEngine::Postgres, $this->environment, $anchor->createdAt)['segments'];
        $window = RecoveryObjectivesInspector::ARCHIVE_RATE_WINDOW_SECONDS;
        $recent = $this->wal->walVolumeSince(DatabaseEngine::Postgres, $this->environment, $now->modify('-' . $window . ' seconds'))['segments'];

        return PitrAnchorReading::at($anchor->createdAt, $sinceAnchor, $recent / $window);
    }
}
