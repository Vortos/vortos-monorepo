<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use DateTimeImmutable;
use Vortos\Backup\DR\PitrAnchorReaderInterface;
use Vortos\Backup\DR\PitrAnchorReading;
use Vortos\Backup\Health\WalFileSizeResolverInterface;

/**
 * The newest pgBackRest backup (full or differential — either is a complete restore point) and the WAL
 * archived after it. The repository reports only the archive's ends, so the write rate is the average
 * since the anchor: with daily differentials that is at most a day, the same window the catalog uses.
 */
final class PgBackRestPitrAnchorReader implements PitrAnchorReaderInterface
{
    public function __construct(
        private readonly PgBackRestClient $client,
        private readonly WalFileSizeResolverInterface $segmentSize,
    ) {}

    public function read(DateTimeImmutable $now): PitrAnchorReading
    {
        $info = $this->client->info();
        $newest = $info->newest();

        if ($newest === null || $info->archiveMax === null) {
            return PitrAnchorReading::none('the pgBackRest repository holds no valid backup, so there is no point-in-time recovery to time');
        }

        $bytes = $this->segmentSize->segmentBytes();
        $since = WalFileName::parse($info->archiveMax)->sequence($bytes) - WalFileName::parse($newest->walStop)->sequence($bytes);
        $elapsed = max(1, $now->getTimestamp() - $newest->stoppedAt->getTimestamp());

        return PitrAnchorReading::at($newest->stoppedAt, $since, max(0, $since) / $elapsed);
    }
}
