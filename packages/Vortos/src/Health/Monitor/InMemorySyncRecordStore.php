<?php

declare(strict_types=1);

namespace Vortos\Health\Monitor;

final class InMemorySyncRecordStore implements SyncRecordStoreInterface
{
    /** @var array<string, array{hash: string, monitorId: string}> */
    private array $records = [];

    public function lastHash(string $env, string $journeyKey): ?string
    {
        return $this->records[$this->key($env, $journeyKey)]['hash'] ?? null;
    }

    public function record(string $env, string $journeyKey, string $hash, string $monitorId): void
    {
        $this->records[$this->key($env, $journeyKey)] = ['hash' => $hash, 'monitorId' => $monitorId];
    }

    public function monitorId(string $env, string $journeyKey): ?string
    {
        return $this->records[$this->key($env, $journeyKey)]['monitorId'] ?? null;
    }

    private function key(string $env, string $journeyKey): string
    {
        return $env . '|' . $journeyKey;
    }
}
