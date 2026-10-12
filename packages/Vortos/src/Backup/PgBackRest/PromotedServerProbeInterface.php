<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

interface PromotedServerProbeInterface
{
    /**
     * The LSN of the last WAL record the server replayed, once it has finished recovery and accepts
     * connections as a primary; null while it is unreachable, starting, or still recovering.
     *
     * @param array{host: string, port: int, user: string, password: string, dbname: string} $connection
     */
    public function promotedReplayLsn(array $connection): ?string;
}
