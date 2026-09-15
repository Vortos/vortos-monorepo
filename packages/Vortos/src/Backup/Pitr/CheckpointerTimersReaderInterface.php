<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

interface CheckpointerTimersReaderInterface
{
    /** @throws \Throwable when the cluster cannot be asked */
    public function read(): CheckpointerTimers;
}
