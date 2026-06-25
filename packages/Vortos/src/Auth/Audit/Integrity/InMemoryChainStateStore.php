<?php

declare(strict_types=1);

namespace Vortos\Auth\Audit\Integrity;

use Vortos\Auth\Audit\AuditEntry;

final class InMemoryChainStateStore implements ChainStateStoreInterface
{
    private int $sequence = 0;
    private string $prevHash;

    public function __construct()
    {
        $this->prevHash = AuthAuditHashChain::GENESIS_HASH;
    }

    public function appendChained(callable $builder): AuditEntry
    {
        $entry = $builder($this->sequence, $this->prevHash);

        $this->sequence = $entry->sequence + 1;
        $this->prevHash = $entry->contentHash;

        return $entry;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getPrevHash(): string
    {
        return $this->prevHash;
    }
}
