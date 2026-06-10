<?php

declare(strict_types=1);

namespace Vortos\Paddle\Inbox;

interface PaddleInboxWorkerInterface
{
    /**
     * Processes one batch of due pending inbox rows.
     *
     * @return int Number of rows that reached `processed` in this batch
     */
    public function process(): int;
}
