<?php

declare(strict_types=1);

namespace Vortos\Paddle\Inbox;

interface PaddleInboxReplayStoreInterface
{
    public function countDead(?int $id = null, ?string $eventType = null): int;

    /** @return array<int, array<string, mixed>> */
    public function listDead(int $limit, ?int $id = null, ?string $eventType = null): array;

    /**
     * Moves dead rows back to pending with attempts reset so the worker picks
     * them up immediately. completed_handlers is preserved — handlers that
     * already succeeded are not re-run.
     *
     * @return int Number of rows revived
     */
    public function replayDead(int $limit, ?int $id = null, ?string $eventType = null): int;
}
