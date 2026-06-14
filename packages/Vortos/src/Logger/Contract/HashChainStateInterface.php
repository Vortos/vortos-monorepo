<?php

declare(strict_types=1);

namespace Vortos\Logger\Contract;

/**
 * Stores the rolling hash for HashChainProcessor's tamper-evident chain.
 *
 * The default binding (InMemoryHashChainState) keeps the chain in-process —
 * sufficient to detect post-hoc edits to a single process's log output, but
 * each process/worker starts its own chain. For a single cross-process chain
 * (e.g. one chain per audit log file shared by multiple FrankenPHP workers),
 * bind this interface to a Redis- or DB-backed implementation in your app's
 * container configuration.
 */
interface HashChainStateInterface
{
    public function last(): string;

    public function setLast(string $hash): void;
}
