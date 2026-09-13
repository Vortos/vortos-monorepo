<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

interface ArchiverStatusReaderInterface
{
    /** @throws \Throwable when the cluster cannot be asked */
    public function read(): ArchiverStatus;
}
