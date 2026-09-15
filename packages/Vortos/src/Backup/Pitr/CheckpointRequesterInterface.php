<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

interface CheckpointRequesterInterface
{
    /** Runs CHECKPOINT; needs superuser or the pg_checkpoint role. @throws \Throwable when refused */
    public function checkpoint(): void;
}
